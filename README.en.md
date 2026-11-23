# Handla (Shopping lists)

*[Svensk version](README.md)*

Shopping lists that learn how you shop. The tool is written in PHP and MySQL/MariaDB and built for your phone in the store. It works just as well on a desktop for planning. There is no framework and no build step.

> The user interface is in **Swedish**. This README explains everything in English.

## Features

**Lists**
- Reusable lists tied to a store, for example *Veckohandling* (weekly shop) at *Willys*.
- Add items with suggestions from your item register as you type. Each row can have a quantity and a comment.
- **Temporary lists** for one-off occasions, like a barbecue. They never affect the statistics.

**Shopping mode ("Börja handla")**
- Each item becomes a large checkbox. Checked items move to the bottom with strikethrough, and tapping again undoes it.
- **"Fanns inte"** (out of stock): the item stays for next time without affecting the statistics.
- You can switch store for this trip only and add items while shopping.
- The screen stays on while you shop (Wake Lock).
- **"Klar"** (done): the cost is optional. Checked items leave the list and the rest stays. If you tap Klar too early, you can undo it.

**What the tool learns**

Everything is based on your past trips from lists that aren't temporary:
- **Picking order per store**: items are sorted in the order you usually check them off. New items are sorted by their department, for example "Kyl" (dairy), until the order has been learned.
- **When you last bought the item**, **how often you usually buy it** and **in which stores**.
- **Suggestions** while you plan a list:
  - *Dags att köpa* (due): items you buy regularly at this store that are coming due.
  - *Sällanköp* (infrequent): items you buy less often than every four weeks, shown with your usual interval.
  - *Annat du brukar köpa här* (other things you usually buy here).

**Costs**
- Per trip, week, month and year, filterable by store and list.
- Key figures for the year, averages per week, month and trip, and a bar chart of the last 12 months.
- You can add or change a cost afterwards.

**Item register**
- All the statistics per item.
- Rename items or change their department, merge duplicates and delete items. Stores are managed in the same place.

**Multiple devices and poor coverage**
- **Live sync**: two phones shopping from the same list see each other's check-offs within a few seconds.
- **Offline**: without a connection, check-offs are saved on the phone and sent automatically when the connection is back.

## Requirements

- PHP 7.4 or later (8.x recommended) with `mysqli` (mysqlnd) and `mbstring`
- MySQL 5.7+ or MariaDB 10.3+, with InnoDB, which the links between tables require
- HTTPS is recommended, and Wake Lock only works over HTTPS.

## Installation

1. **Create a database** and run `sql/schema.sql` in it, for example in phpMyAdmin under the SQL tab.
2. **Sample data (optional):** run `sql/exempeldata.sql`. It contains three lists, two stores and twelve weeks of shopping history, so suggestions, picking order and costs show up right away. It puts the data on user ID 1.
3. **Copy** `config.example.php` to `config.php` and fill in the database details and `LOGIN_USER`.
4. **Upload** the folder to your web server.
5. **Open** `handla.php` in your browser. The first time, a setup guide appears:
   1. Choose a password, and a hash is created for it.
   2. Paste the line into `config.php` as `LOGIN_PASSWORD_HASH` and reload the page.
   3. Log in.

`config.php` is listed in `.gitignore`, so your credentials never end up in git.

## Login modes

The login mode is set with `AUTH_MODE` in `config.php`:

| Mode | Description |
|---|---|
| `login` | Built-in login with one username and password. The default. |
| `demo` | No login. Each visitor gets their own sandbox with sample data, deleted after `DEMO_TTL_HOURS` hours. Requires `sql/demo.sql` instead of `exempeldata.sql`. |
| `ll2` | Shares the session with an existing login system (`ll2.php`). Used in the author's own installation, and you can ignore it. |

## How the statistics work

- **Picking order:** when you tap Klar, each checked item gets a position between 0 (first) and 1 (last). An item's place in a store is the average of the ten most recent times.
- **Interval:** the average number of days between the eleven most recent purchase days. At least two purchases are needed.
- **Due:** at least 75 % of the interval has passed since the last purchase (85 % for infrequent items), and the item is usually bought at this store or at least a quarter of the time there.
- The cutoff for infrequent items is 28 days. You can change it with the `SALLAN_DAGAR` constant in `handla.php`.

## Files

| File | Contents |
|---|---|
| `handla.php` | Overview, item register, costs, statistics and everything that runs on the server |
| `handla-lista.inc.php` | The list view: planning, shopping, live sync and offline queue |
| `common.php` | Shared hub: database, login, CSRF protection, menu and demo mode |
| `config.example.php` | Settings template |
| `demo.php` | Copies sample data into a visitor's sandbox (demo mode only) |
| `minsida.css` | Styling |
| `sql/` | `schema.sql`, `exempeldata.sql` (sample data) and `demo.sql` |

## Security

- All database queries use prepared statements.
- Every change is protected by a CSRF token.
- Every change is checked against the logged-in user.
- The password is stored as a `password_hash`.

## License

MIT – see [LICENSE](LICENSE).
