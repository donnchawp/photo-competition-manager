# A "no file chosen" code from the upload services

The upload services don't report a blank file input on its own. `Image_Processor::validate()` returns `upload_error` and `Member_CSV_Importer::import()` returns `upload_failed`, both "File upload failed.". So each form checks for `UPLOAD_ERR_NO_FILE` before calling them:

- `src/admin/class-submissions-controller.php`: `missing_file`
- `src/admin/class-members-controller.php`: `no_file`
- `src/public/class-upload-shortcode.php`: `image_missing`

The three checks are written slightly differently, and the idea was to have the services return a `no_file` code so the callers could drop them.

## Why this is out of scope

No user sees a problem. Every form already shows the right "please select a file" message, and the differences between the three checks don't change what they do (PHP always stores `error` as an int, so the shortcode's strict compare without `absint()` is fine).

The payoff is only that a future upload form can't get this wrong. This plugin runs one club's competitions and has had the same three upload forms for a long time, so that form may never come. Meanwhile, moving the check into the services changes which message wins: `Entries::add()` checks membership, competition state, category and voting before it looks at the file, so a blank submit to a closed competition would say "closed" rather than "select a file". That's a behaviour change to design and test for no visible gain.

If a fourth upload form is added, copy the admin controllers' check:

```php
if ( UPLOAD_ERR_NO_FILE === absint( $_FILES['field']['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
```

## Prior requests

- #279: Upload services can't say "no file", so three callers pre-check it differently
