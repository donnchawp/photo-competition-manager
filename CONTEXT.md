# Photo Competition Manager

Runs a photography club's monthly competitions: members upload images, members or judges score them, and results are published by category and grade. A club can run more than one kind of competition, each a competition type with its own members, categories and settings.

## Language

### Members

**Grade**:
A member's skill level, such as Beginner, Intermediate or Advanced. Members compete only against others in the same grade. The club defines one list of grades, and every competition of every type uses it. Every member has exactly one grade from that list, and a member without one is a data error. Renaming a grade keeps its members in it, and a grade can't be removed while any member holds it. A competition type can show a grade under another name, such as Cup for Advanced. Members and the public see that name everywhere, and admins see it with the grade's own name, as "Cup (Advanced)". The grade itself, its order and its members are unchanged. A `{grade}` in a competition's title becomes each entrant's own grade name on their upload page and in their emails, and elsewhere the names of every grade that has entries, joined with " / ".
_Avoid_: Level, class, division

**Type tag**:
The link between a member and a competition type they belong to. A member can have several, and the types they are tagged with decide which competitions they can enter. With one type every member carries its tag automatically. With several, an active member who has none is flagged, since they can enter nothing. Admins set tags on the Members screen, one member or many at once, and by a `types` column in a CSV import that can name several types.
_Avoid_: Membership, group

**Deactivated member**:
A member who has left but whose record and history stay. They get no upload or voting links and can't upload or vote, and an admin can make them active again.
_Avoid_: Archived member, former member (that's a deleted one)

**Deleting a member**:
Removing everything the club holds about a member, whether an admin does it from the Members screen or for an erasure request. Their entries, with their images and originals, go, and so does anything else that names them. The votes they cast stay without anything that identifies them, so the scores of every competition they voted in don't change. A member can be deleted while voting is open; their entries leave that category's ballot, as a disqualified entry would.
_Avoid_: Erasing (except for WordPress's own tool), removing a member

### Competitions

**Competition type**:
A kind of competition the club runs, such as Individual or Juniors, with its own name, categories, default settings and grade display names. Its settings cover upload limits, voting (who scores and the score range) and the slideshow. A type has at least one category, and the club has at least one type. A competition belongs to one type, chosen when it is created; with only one type there is nothing to choose. The competition copies the type's categories and settings, and the club's page links, so editing the type later changes only competitions created afterwards. A competition's type can't be changed once it has entries. A type can't be deleted while a competition uses it, or when it is the club's only type. The club's grades, email settings, page links, voting form layout and log retention stay in Settings and apply to every type. A club upgraded from before types has one type, Individual, built from its old settings: every member is tagged with it and every competition belongs to it, open to everyone.
_Avoid_: Competition kind, series, league

**Category**:
A kind of image a competition accepts, such as Colour or Black & White, with a quota of entries per member. Entries are voted on and ranked within their category. Each competition type defines a list of categories, and a new competition starts with a copy of its type's list. A competition can change its own list, which then replaces its type's. A competition from before types that had no categories of its own was given the club's old list when the club upgraded.
_Avoid_: Section, class

**Entry**:
An image a member has entered in one category of a competition. It has an entry image (resized for voting and the slideshow), a thumbnail, and an original.
_Avoid_: Submission, image (for the record)

**Entrant**:
A member who may enter a competition. A competition is either open to everyone in its type, where every active member tagged with the type is an entrant, or for selected entrants only, where an admin picks them. Entrants can be added until voting starts; if uploads are open, a new entrant is sent their upload link. An entrant can be removed unless they have entries, and removing one revokes their link. An admin can create a member and enter them in one step. Only entrants get upload links and reminders, and only entrants can upload, on the upload page or through the API. In a selected-entrants competition only entrants get voting links. When voting opens, the "voting opened" email goes to the members of the competition's type.
_Avoid_: Participant, contestant

**Original**:
The full-size file a member uploaded for an entry, kept in the media library for export until an admin discards it. Discarding originals leaves the entries in place. WordPress also keeps a 2560px `-scaled` copy of a larger original as its attached file; that copy isn't the original, and export uses the full-size file.

**Competition phase**:
Where a competition is in its life: Scheduled, Accepting uploads, Uploads closed, Results published, Closed or Archived. It's Closed from its close date. The edit form has only the day, so setting an open competition's close date to today or earlier closes it when the form is saved, and saving the form with the day unchanged keeps the time it closed.
_Avoid_: Status, state

### Voting

**Voting stage**:
How far one category has got on competition night: Not started, Previewed, Voting, Slideshow shown, Critique or Done. A competition has one voting stage per category. Votes are accepted at Voting and Slideshow shown, and only one category in the club can accept votes at a time. Once a category's voting has started (any stage past Previewed), its entries are fixed: none can be added, moved in or out, or removed, and uploads can't reopen, until the category is reset.
_Avoid_: Step

**Ballot**:
One voter's scores for every entry in one category of a competition, their own entries included. A voter casts at most one ballot per category, and it is kept whole or not at all. A judge's ballot scores every entry in the open category, from the competition's score list.
_Avoid_: Votes (for the set), submission

**Vote**:
The score one entry got on one ballot.

**Voter**:
Whoever casts a ballot. A link voter is a member, proved by their voting link. A judge is proved by their judge link. A named voter is a name given with the club's voting password, taken on trust. Names that differ only in case, accents or surrounding spaces belong to the same voter, so "Seán" and "sean" are one voter.
Asking for a voting link replaces the member's earlier one. That's an accepted trade-off: anyone who knows a member's email can make their earlier link stop working, at most once every 5 minutes, but the member always receives the newest link, so no votes are lost.
_Avoid_: User, member (for a named voter)

**Judge**:
Someone outside the club who scores a judged competition. The club keeps one list of judges, a name and an email, apart from its members. An admin chooses the judges for each competition, and each gets a private link that works only while they are chosen for it and a category is open. A judge scores every entry in the open category once. Deleting a judge keeps their scores without anything that identifies them, and a privacy erasure request covers judges.
_Avoid_: Adjudicator, voter (for a judge)

**Judged competition**:
A competition whose "Who scores" setting is Judges rather than the voting password or members' voting links. Only its judges score it: members get no voting links, the password doesn't work, they aren't sent "voting opened", and upload emails don't mention voting. Total score is the sum of the judges' scores. Public results pages and the results email show only the total and position; admins can still see individual scores.

**Reset**:
Returning a category's voting stage to Not started, optionally clearing its votes. It lifts the rule that fixes a started category's entries (see **Voting stage**). Votes it keeps still stop entries being added to the category or moved in or out.

**Self-vote**:
A member's vote on their own entry. Members vote on their own entries like any others, and are expected to give them the top score. It doesn't apply to judges, who aren't members and have no entries.

### Results

**Total score**:
The sum of an entry's votes. An entry with no votes has a total score of 0. It's worked out from the current votes until it's recorded, when results are published or, if they never were, when the competition closes.
_Avoid_: Score (alone), points

**Ungraded entry**:
An entry whose member has no grade from the club's list, or no longer exists. It is a data error: only admins see it, so they can fix it, and it has no position in anything members see. In recorded results, an entry whose member was deleted after recording isn't ungraded: it's a former member's.

**Position**:
An entry's place within its category and grade, ordered by total score. Tied entries share a position, and the next score takes the next position (1, 1, 2), so no position is skipped. Recorded with the total score.
_Avoid_: Rank, place

**Recorded results**:
Each entry's total score, vote count, grade and position, kept when results are published, or the first time they're needed once the competition has closed. Every results page, the Results screen, the export and the results email read them from then on, so deleting a member, removing an entry or changing a grade afterwards moves nobody. A recorded entry keeps the grade it was entered in. Publishing again replaces them until the competition closes; after that, results can't be hidden and publishing keeps them. A record made before the competition closes or is archived counts only while results are published. If they're hidden when it closes or is archived, the next time they're needed they're recorded afresh from the entries and votes as they are then. To correct them after the competition has closed, move its close date into the future, then hide and publish the results again.
_Avoid_: Snapshot, frozen results

**Former member**:
How recorded results show an entry whose member has since been deleted, for whatever reason. No name is kept, the image went with the entry, and the entry keeps its position. The votes a deleted member cast are a former member's too: they still count, and a ballot cast under their name shows as "Former member #" and the number their record had, so each deleted voter stays distinct.

### Email

**Email template**:
The subject and body one kind of email is sent with, with merge tags filled in for each member. Every kind has a default template, and an admin's edited template replaces it until they restore the default. What the Email Templates screen shows is what members get.

**Requested email**:
An email sent because a member or an admin asked for it: an upload link, a voting link, or the results. It always sends.

**Notification**:
An email sent automatically when something happens, such as voting opening in a category or a member's upload being saved. A club can switch each kind of notification off.
_Avoid_: Alert
