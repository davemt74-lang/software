# Stonefellow v104 — Artist Workspaces + Multi-Role Accounts

## Artist account type

- Added **Artist** as a first-class Stonefellow account type.
- Artist accounts can enter the existing administration workspace and manage the creative side of Stonefellow.
- Default Artist access includes Tracks, Albums, Shows, Photos, Merch, Posts, Messages, Artist Profile, Knowledge Base, listening analytics, shared production notes, Agent Chat and Artist Team management.
- Artist accounts do **not** receive platform-level `users.manage`, `ai.manage` or `permissions.manage` access unless another assigned account type explicitly grants it.
- The administration shell identifies Artist-only sessions as **Stonefellow Artist** rather than Stonefellow Admin.

## Multiple account types per user

- Users can now hold **multiple account types at the same time**, such as **Artist + Producer** or **Manager + Producer**.
- `users.role` remains the **Primary Account Type** for backward compatibility with older Stonefellow code.
- Additional assigned types are stored in the new `user_account_types` relationship table.
- Existing accounts are automatically backfilled into `user_account_types` from their current primary role.
- Permissions are the union of every account type assigned to the signed-in user.
- Role-restricted visibility now recognizes secondary account types.
- Admin → Users now exposes Account Type checkboxes plus a Primary Account Type selector.
- The Users list displays all assigned types and identifies the primary type.
- Last-active-Admin protection recognizes Admin whether it is primary or secondary.

## Artist team accounts

- Added **Team** to the Artist workspace navigation.
- Each Artist can create up to **2 team accounts**.
- Artist-created accounts can be assigned only **Manager** or **Producer**.
- Artists can edit the team member's name, email, assigned role, password and active status.
- Artists can delete only accounts attached to their own Artist workspace.
- Deleting a team member frees the Artist's team seat.
- Artist-created team seats are synchronized into the same multi-role account table.
- A global Admin can later promote a delegated team account into a broader multi-role user. When that happens, the delegated Artist ownership is removed so the Artist can no longer administer that promoted global account.
- Team account passwords retain Stonefellow's 12-character minimum.
- Create/update operations remain CSRF protected and transactional.

## Workspace relationships

- Added `artist_team_members` to associate delegated Manager/Producer accounts with the Artist that created them.
- A delegated team member belongs to only one Artist workspace in v104.
- Added `user_account_types` for many-to-one account-type assignments.
- Runtime access upgrade and `upgrade-stonefellow-v104.sql` both create/backfill the multi-role table.
- Multi-role synchronization performs only DML inside user/team transactions so MySQL DDL cannot implicitly commit those transactions.

## Permissions and collaboration

- Added the `team.manage` permission.
- Artist is included in role labels and Artist-only content visibility.
- Team Chat authorizes qualifying secondary account types as well as primary roles.
- The legacy v81 browser rail receives a compatible UI role after server authorization, so combinations such as Fan + Producer or Artist + Producer are not rejected by the older JavaScript gate.
- Track Producer assignment and the Producer selector recognize users whose Producer type is secondary.
- Admin remains unrestricted and keeps all permission/API/security controls.

## Scope

v104 adds the Artist operator hierarchy and multi-role identity layer to the existing Stonefellow workspace. It does not convert every existing catalog/content query into separate multi-tenant databases. Artist-created team ownership remains scoped so an Artist cannot manage unrelated platform accounts.

## Validation

`Stonefellow v104 validation` checks PHP syntax, JavaScript syntax, Artist permission boundaries, multi-role schema/backfill, unioned permissions, primary-role compatibility, last-Admin protection, the two-seat limit, delegated-role restrictions, ownership checks, transaction safety, secondary Producer assignment, Team Chat secondary-role authorization, and migration coverage.
