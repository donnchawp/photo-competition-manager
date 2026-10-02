# Photo Competition Manager

Runs a photography club's monthly competitions: members upload images, members vote on them, and results are published by category and grade.

## Language

### Members

**Grade**:
A member's skill level, such as Beginner, Intermediate or Advanced. Members compete only against others in the same grade. The club defines one list of grades, and every competition uses it. Every member has exactly one grade from that list, and a member without one is a data error. Renaming a grade keeps its members in it, and a grade can't be removed while any member holds it.
_Avoid_: Level, class, division

### Results

**Total score**:
The sum of an image's current votes. An image with no votes has a total score of 0.
_Avoid_: Score (alone), points

**Ungraded entry**:
An image whose member has no grade from the club's list, or no longer exists. It is a data error: only admins see it, so they can fix it, and it has no position in anything members see.

**Position**:
An image's place within its category and grade, ordered by total score. Tied images share a position, and the next score takes the next position (1, 1, 2), so no position is skipped.
_Avoid_: Rank, place
