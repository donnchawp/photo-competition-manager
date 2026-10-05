# Photo Competition Manager

Runs a photography club's monthly competitions: members upload images, members vote on them, and results are published by category and grade.

## Language

### Members

**Grade**:
A member's skill level, such as Beginner, Intermediate or Advanced. Members compete only against others in the same grade. The club defines one list of grades, and every competition uses it. Every member has exactly one grade from that list, and a member without one is a data error. Renaming a grade keeps its members in it, and a grade can't be removed while any member holds it.
_Avoid_: Level, class, division

### Competitions

**Category**:
A kind of image a competition accepts, such as Colour or Black & White, with a quota of entries per member. Entries are voted on and ranked within their category. The club defines a list of categories, and a new competition starts with a copy of it. A competition can change its own list, which then replaces the club's. A competition with no categories of its own uses the club's list.
_Avoid_: Section, class

**Entry**:
An image a member has entered in one category of a competition. It has an entry image (resized for voting and the slideshow), a thumbnail, and an original.
_Avoid_: Submission, image (for the record)

**Original**:
The full-size file a member uploaded for an entry, kept for export until an admin discards it. Discarding originals leaves the entries in place.

**Competition phase**:
Where a competition is in its life: Scheduled, Accepting uploads, Uploads closed, Results published, Closed or Archived.
_Avoid_: Status, state

### Voting

**Voting stage**:
How far one category has got on competition night: Not started, Previewed, Voting, Slideshow shown, Critique or Done. A competition has one voting stage per category. Votes are accepted at Voting and Slideshow shown, and only one category in the club can accept votes at a time.
_Avoid_: Step

**Reset**:
Returning a category's voting stage to Not started, optionally clearing its votes.

**Self-vote**:
A member's vote on their own entry. Members vote on their own entries like any others, and are expected to give them the top score.

### Results

**Total score**:
The sum of an entry's current votes. An entry with no votes has a total score of 0.
_Avoid_: Score (alone), points

**Ungraded entry**:
An entry whose member has no grade from the club's list, or no longer exists. It is a data error: only admins see it, so they can fix it, and it has no position in anything members see.

**Position**:
An entry's place within its category and grade, ordered by total score. Tied entries share a position, and the next score takes the next position (1, 1, 2), so no position is skipped.
_Avoid_: Rank, place
