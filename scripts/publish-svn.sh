#!/usr/bin/env bash
#
# Publishes a released version to the WordPress.org SVN repository: builds the
# zip (make release) from the release's git tag, syncs it into trunk, syncs the
# listing screenshots into assets, then commits trunk and copies it to
# tags/<version>. Run after the git tag and GitHub release exist.
#
# Usage: scripts/publish-svn.sh [x.y.z]   (an argument must match the Stable tag)
#        scripts/publish-svn.sh --assets-only
#
# --assets-only commits just the listing assets (screenshots and the Live
# Preview blueprint) from .wordpress-org/, without building or tagging, so
# they can change between releases.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

PLUGIN_SLUG="photo-competition-manager"
SVN_URL="https://plugins.svn.wordpress.org/$PLUGIN_SLUG/"
SVN_DIR="release/svn"
ZIP_FILE="$PLUGIN_SLUG.zip"
LISTING_ASSETS_DIR=".wordpress-org"

if ! command -v svn >/dev/null 2>&1; then
	echo "svn is not installed." >&2
	exit 1
fi

# No --delete: a banner or icon uploaded to assets some other way must survive.
sync_listing_assets() {
	if [[ -d "$LISTING_ASSETS_DIR" ]]; then
		echo "Syncing $LISTING_ASSETS_DIR/ into assets..."
		rsync -a --exclude='.svn/' --exclude='.DS_Store' "$LISTING_ASSETS_DIR/" "$SVN_DIR/assets/"
	fi
}

# Stage additions and removals from svn status, and give listing images their MIME type.
stage_changes() {
	(
		cd "$SVN_DIR"
		while IFS= read -r line; do
			flag="${line:0:1}"
			# svn status puts the flags in columns 1-7, then a space, then the path.
			path="${line:8}"
			# A trailing @ stops svn reading an @ in a file name as a peg revision.
			case "$flag" in
				'?') svn add "${path}@" ;;
				'!') svn rm "${path}@" ;;
			esac
		done < <(svn status)

		# Without a MIME type the browser downloads a listing image instead of showing it.
		for image in assets/*.png assets/*.jpg; do
			[[ -e "$image" ]] || continue
			case "$image" in
				*.png) svn propset -q svn:mime-type image/png "${image}@" ;;
				*.jpg) svn propset -q svn:mime-type image/jpeg "${image}@" ;;
			esac
		done
	)

	echo
	echo "----- svn status -----"
	( cd "$SVN_DIR" && svn status )
	echo "----------------------"
}

if [[ "${1:-}" == "--assets-only" ]]; then
	rm -rf "$SVN_DIR"
	mkdir -p "$SVN_DIR"

	echo "Checking out $SVN_URL (assets only)..."
	svn checkout "$SVN_URL" "$SVN_DIR" --depth=empty
	( cd "$SVN_DIR" && svn up assets )

	sync_listing_assets
	stage_changes

	if [[ -z "$(cd "$SVN_DIR" && svn status -q)" ]]; then
		echo "The listing assets on WordPress.org are already up to date."
		exit 0
	fi

	read -r -p "Commit these listing assets to WordPress.org? [y/N] " confirm
	if [[ "$confirm" != "y" && "$confirm" != "Y" ]]; then
		echo "Stopped before committing. $SVN_DIR is left for inspection."
		exit 1
	fi

	( cd "$SVN_DIR" && svn commit -m "Update listing assets" )
	echo "Published the listing assets for $PLUGIN_SLUG to WordPress.org."
	exit 0
fi

VERSION="$(sed -nE 's/^Stable tag: ([0-9A-Za-z.+-]+).*/\1/p' src/readme.txt | head -1)"
if [[ -z "$VERSION" ]]; then
	echo "Could not read Stable tag from src/readme.txt." >&2
	exit 1
fi

EXPECTED="${1:-}"
if [[ -n "$EXPECTED" && "$EXPECTED" != "$VERSION" ]]; then
	echo "Requested version $EXPECTED does not match the Stable tag $VERSION." >&2
	exit 1
fi

# SVN must get exactly what the GitHub release shipped, so the files the zip is
# built from must be as they were at the release tag, committed or not.
GIT_TAG="v$VERSION"
BUILD_INPUTS=(src assets/src assets/package.json assets/package-lock.json .releaseignore)
if ! git rev-parse -q --verify "refs/tags/$GIT_TAG" >/dev/null; then
	echo "Git tag $GIT_TAG doesn't exist. Tag and release on GitHub first." >&2
	exit 1
fi
if ! git diff --quiet "$GIT_TAG" -- "${BUILD_INPUTS[@]}"; then
	echo "These files differ from $GIT_TAG, so the zip wouldn't match the release:" >&2
	git diff --stat "$GIT_TAG" -- "${BUILD_INPUTS[@]}" >&2
	echo "Check out $GIT_TAG's build files first: git checkout $GIT_TAG -- ${BUILD_INPUTS[*]}" >&2
	exit 1
fi
# make release copies all of src/, so an untracked file there would ship too.
UNTRACKED="$(git ls-files --others --exclude-standard -- src)"
if [[ -n "$UNTRACKED" ]]; then
	echo "Untracked files in src/ would end up in the zip:" >&2
	echo "$UNTRACKED" >&2
	exit 1
fi

echo "Building $ZIP_FILE..."
make release

ZIP_VERSION="$(unzip -p "$ZIP_FILE" "$PLUGIN_SLUG/readme.txt" | sed -nE 's/^Stable tag: ([0-9A-Za-z.+-]+).*/\1/p' | head -1)"
if [[ "$ZIP_VERSION" != "$VERSION" ]]; then
	echo "Stable tag in $ZIP_FILE ($ZIP_VERSION) doesn't match src/readme.txt ($VERSION)." >&2
	exit 1
fi

rm -rf "$SVN_DIR"
mkdir -p "$SVN_DIR"

echo "Checking out $SVN_URL (trunk, tag names, assets)..."
svn checkout "$SVN_URL" "$SVN_DIR" --depth=empty
( cd "$SVN_DIR" && svn up trunk && svn up tags --depth=immediates && svn up assets )

if [[ -e "$SVN_DIR/tags/$VERSION" ]]; then
	echo "tags/$VERSION already exists in SVN." >&2
	exit 1
fi

# Trunk becomes a byte-for-byte copy of the zip.
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
unzip -q "$ZIP_FILE" -d "$TMP"
if [[ ! -d "$TMP/$PLUGIN_SLUG" ]] || [[ -z "$(ls -A "$TMP/$PLUGIN_SLUG")" ]]; then
	echo "The unzipped build is empty. Refusing to sync it into trunk with --delete." >&2
	exit 1
fi
echo "Syncing the build into trunk..."
rsync -a --delete --exclude='.svn/' "$TMP/$PLUGIN_SLUG/" "$SVN_DIR/trunk/"

sync_listing_assets
stage_changes

read -r -p "Commit trunk and assets, and create tags/$VERSION on WordPress.org? [y/N] " confirm
if [[ "$confirm" != "y" && "$confirm" != "Y" ]]; then
	echo "Stopped before committing. $SVN_DIR is left for inspection."
	exit 1
fi

(
	cd "$SVN_DIR"
	svn commit -m "Update to version $VERSION"
	# ^/ is the root of the whole plugins repository, not this plugin.
	svn cp "^/$PLUGIN_SLUG/trunk" "^/$PLUGIN_SLUG/tags/$VERSION" -m "Tagging version $VERSION"
)

echo "Published $PLUGIN_SLUG $VERSION to WordPress.org."
