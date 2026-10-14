# Putting CIMS online (cloud server, HTTPS)

When this is done every branch opens **https://cims.yourshop.com** (your own
address), can install CIMS as a Windows app, and sees the same stock. The
certificate renews itself, the database is backed up every hour, and only the
web and SSH ports are open.

Time: about an hour, most of it waiting. Cost: roughly US$12–15 a month for the
server plus ₱600–1,000 a year for the domain.

---

## Before moving day (you)

**1. Buy a domain.** Any registrar (Cloudflare, Namecheap, or dotPH for a
`.ph` address). You will use a sub-address of it, for example `cims.yourshop.com`.

**2. Rent a server.** At DigitalOcean, Vultr or AWS Lightsail, create one with:

| Setting | Choose |
|---|---|
| System | **Ubuntu 22.04 LTS** (exactly this one: it has PHP 8.1, which CIMS is tested on) |
| Region | **Singapore** (closest to the Philippines) |
| Size | 2 GB memory, 1 CPU, 50 GB disk |
| Backups | **Turn on** the provider's automatic backups |
| Sign-in | an SSH key, or a strong root password |

Write down the server's IP address.

**3. Point the address at the server.** At your domain's DNS settings add an
**A record**: name `cims`, value = the server's IP. It can take a few minutes to
an hour to take effect; `nslookup cims.yourshop.com` shows the IP when it has.

---

## Moving day

**4. Stop using CIMS on this computer.** Tell the branches, close the tills.
From here the server is the only copy that changes; anything rung up on this PC
afterwards would not be on the server.

**5. Build the bundles.** On this PC, in Git Bash, in the project folder:

```bash
bash deploy/make-release.sh --with-data
```

This makes two files in `dist/`: `cims-code-…tar.gz` (the program) and
`cims-data-…tar.gz` (the database, the encryption key, the sign-in keys and
uploaded images). **The data bundle is as sensitive as a password.**

**6. Copy them to the server** (replace the IP):

```bash
scp dist/cims-*.tar.gz deploy/server-setup.sh deploy/install-release.sh deploy/nginx-cims.conf deploy/env.production.example root@203.0.113.10:/root/
```

**7. Set up the server** (about 10 minutes). Sign in with `ssh root@203.0.113.10`, then:

```bash
bash server-setup.sh cims.yourshop.com you@yourshop.com
```

The email gets certificate-expiry notices from Let's Encrypt (running this
agrees to their terms). If it stops at the certificate step saying the address
points elsewhere, the DNS from step 3 is not live yet: wait and run it again.

**8. Install CIMS with your data:**

```bash
bash install-release.sh cims-code-*.tar.gz cims-data-*.tar.gz
```

It imports the database, keeps the same encryption key (so the SMTP and Xendit
settings still work), switches off the three test accounts, and finishes by
checking that `https://cims.yourshop.com/login` answers.

**9. Delete the data bundle** on the server (`rm /root/cims-data-*.tar.gz`) and
on this PC (`dist/cims-data-*`).

**10. Check it** at https://cims.yourshop.com:
- sign in; the dashboard, today's sales and stock levels match this PC;
- open a sale and download its PDF invoice;
- Settings → SMTP: send a test email;
- if you take GCash/Maya: in the Xendit dashboard set the webhook to
  `https://cims.yourshop.com/api/xendit/webhook`.

**11. Branches.** On each computer open the address in **Edge**, sign in, and
use the install icon in the address bar (or profile menu → *Install CIMS on
this computer*). Phones: Chrome → *Add to Home screen*. Notifications to phones
and closed browsers now work too (bell → turn on).

**12. This PC** stops being the system: stop its local CIMS server and use the
online address like every other branch. Keep the old copy as an archive only.

---

## Updating CIMS later

```bash
bash deploy/make-release.sh                       # on this PC: code only
scp dist/cims-code-*.tar.gz deploy/install-release.sh root@203.0.113.10:/root/
ssh root@203.0.113.10 "bash install-release.sh /root/cims-code-<newest>.tar.gz"
```

CIMS shows a maintenance page for about a minute, applies any database changes,
and comes back. Installed apps pick up the new version by themselves. Settings,
uploads, logs and backups on the server are never touched by an update.

## Backups

- CIMS backs up the database **every hour** into
  `/var/www/cims/storage/app/backup` on the server.
- The provider's automatic backups cover losing the whole server.
- Once a month, keep a copy somewhere else:
  `scp -r root@203.0.113.10:/var/www/cims/storage/app/backup ./cims-backups`

## Security, as set up

- Debug pages off; cookies sent only over HTTPS.
- Only SSH and web ports open; the database listens on the server only.
- The setup wizard stays closed (the install refuses to finish without the
  `installed` marker); the update screen needs an administrator.
- Test accounts switched off. Review **People → Users** and turn on two-factor
  sign-in for every administrator.
- Ubuntu installs security updates automatically; reboot the server monthly
  (`reboot`, CIMS starts again by itself).
- 12 low-to-medium advisories remain in the framework and PDF library; they go
  away with an upgrade to Laravel 10/11 (a separate project).

## If something goes wrong

| Symptom | Do this |
|---|---|
| Install ends with "Not 200" | `tail -50 /var/www/cims/storage/logs/laravel-*.log` |
| Stuck on the maintenance page | `cd /var/www/cims && sudo -u www-data php artisan up` |
| Certificate step fails | DNS not live yet (step 3); wait, run `server-setup.sh` again |
| Emails or SMS stopped after a change | someone ran `php artisan config:cache`: run `php artisan config:clear` |

**Never run `php artisan config:cache` on this server**: parts of CIMS read
`.env` directly, and cached settings make them read nothing.

---

### Files in this folder

| File | Runs on | Does |
|---|---|---|
| `make-release.sh` | this PC (Git Bash) | builds the code bundle, and with `--with-data` the data bundle |
| `server-setup.sh` | server, once | packages, database, firewall, HTTPS, scheduler |
| `install-release.sh` | server | first install with data, or an update |
| `nginx-cims.conf` | (used by server-setup.sh) | web server settings |
| `env.production.example` | (used by install-release.sh) | production settings template |

The bundles were built and checked on this PC. The two server scripts have
not yet run on a real Ubuntu server (none was available here): the first
run on yours is their test, so keep the terminal output if anything stops.
