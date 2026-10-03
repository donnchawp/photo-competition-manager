# Photo Competition Manager

Runs a photography club's monthly competitions: members upload images, members vote on them, and results are published by category and grade.

## Language

### Members

**Grade**:
A member's skill level, such as Beginner, Intermediate or Advanced. Members compete only against others in the same grade. The club defines one list of grades, and every competition uses it. Every member has exactly one grade from that list, and a member without one is a data error. Renaming a grade keeps its members in it, and a grade can't be removed while any member holds it.
_Avoid_: Level, class, division

### Competitions

**Category**:
A kind of image a competition accepts, such as Colour or Black & White, with a quota of images per member. Images are voted on and ranked within their category. The club defines a list of categories, and a new competition starts with a copy of it. A competition can change its own list, which then replaces the club's. A competition with no categories of its own uses the club's list.
_Avoid_: Section, class

### Results

**Total score**:
The sum of an image's current votes. An image with no votes has a total score of 0.
_Avoid_: Score (alone), points

**Ungraded entry**:
An image whose member has no grade from the club's list, or no longer exists. It is a data error: only admins see it, so they can fix it, and it has no position in anything members see.

**Position**:
An image's place within its category and grade, ordered by total score. Tied images share a position, and the next score takes the next position (1, 1, 2), so no position is skipped.
_Avoid_: Rank, place
