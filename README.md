# Our House — chores, food and recipes

A small website for the whole household, built to be used on a touch-screen tablet (and phones).
Same look as the Refurbisch dashboard: warm brown → orange, Inter font, light + dark mode.

- **Chores** — tick them off with one tap. Repeat (daily, weekly, every N days…), give them to a person,
  or let people **take turns**. Undo if you tick by mistake.
- **Food** — big − / + buttons for what's in stock. At or below the alert amount it goes on the
  **shopping list**; tick "bought" and the stock jumps back to the normal amount.
- **Recipes** — ingredients linked to your food show "in stock / missing". Tick ingredients and
  steps while cooking. "I cooked this" takes the ingredients out of stock; "Add missing" fills the shopping list.
- **Phone alerts** — new chore given to you, your turn, daily reminder for due/overdue chores, food running low.
  Everyone chooses which alerts they want (Settings).

## Requirements
PHP 8.1+ with `pdo_sqlite`, `openssl`, `curl` (all standard on Plesk). No database server, no extra libraries.
**HTTPS is required** for phone alerts (Plesk → SSL/TLS → Let's Encrypt).

## Install on Plesk
1. Create a subdomain, e.g. `house.refurbisch.de` (or any domain), with PHP 8.x and Let's Encrypt.
2. Get the files into its `httpdocs`, either:
   - **Plesk Git (recommended, same as the website):** Websites & Domains → the subdomain → Git →
     remote repository `https://github.com/Xenon112/house-app.git`, branch `main`, deploy to `httpdocs`.
     The repo is private, so use an SSH deploy key (Plesk shows the key; add it on GitHub → repo Settings → Deploy keys, read-only)
     and the address `git@github.com:Xenon112/house-app.git`. Every push to `main` then updates the site.
   - or upload the files by hand.
   `config.php` and `data/` are not in git — they live only on the server, so deploys never overwrite them.
3. Copy `config.sample.php` to `config.php` and change:
   - `setup_key` and `cron_key` → long random text (e.g. from a password generator)
   - `contact_email` → your address (only sent to the push services)
   - optional: `data_dir` → a folder outside `httpdocs`, e.g. `/var/www/vhosts/refurbisch.de/house-data`
4. Open the site → you land on **Set up**: enter the setup key and create your (admin) account.
5. Settings → Household → **Manage people**: add everyone with a username + password.
6. Plesk → **Scheduled Tasks** → Add task → *Fetch a URL* →
   `https://house.example.com/cron.php?key=YOUR_CRON_KEY`, every 15 minutes.
   (Without it, reminders still go out whenever someone opens the app — the task just makes them on time.)

## Phone alerts on each device
- **Android / computer:** log in → Settings → *Turn on alerts on this device* → Allow.
- **iPhone / iPad (iOS 16.4+):** open the site in Safari → Share → *Add to Home Screen* →
  open it from the home screen → log in → Settings → *Turn on alerts on this device*.
- The kitchen tablet: log in once with "Keep me signed in" (stays logged in for 90 days).

## Files
| File | What it is |
|---|---|
| `index.php` | Home: today's chores + shopping list |
| `chores.php`, `chore_edit.php` | Chore list and add/edit |
| `food.php`, `item_edit.php` | Stock, shopping list, add/edit food |
| `recipes.php`, `recipe.php`, `recipe_edit.php` | Recipes |
| `alerts.php`, `settings.php`, `members.php` | Alerts list, settings, household |
| `api.php` | Tick / +− actions (JSON) |
| `push.php`, `sw.js` | Phone alerts (web push, VAPID — same method as the Refurbisch dashboard) |
| `cron.php` | Reminder job |
| `includes/` | Logic (database, login, chores, food, alerts, layout) |
| `data/` | The SQLite database (secret file name, blocked by `.htaccess`) |

## Backup
Download the `.sqlite` file in `data/` (or your `data_dir`). That's all the data.
