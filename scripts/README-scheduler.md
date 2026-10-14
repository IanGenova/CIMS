# The scheduler

Several things in CIMS only happen because Laravel's scheduler is run once a
minute:

| Job | Every | What it does |
|---|---|---|
| `post-ledger` | 5 minutes | Posts any sale, received purchase or expense that has not reached the journal. Documents normally post themselves the moment they are saved; this catches whatever that missed. |
| `deliver-webhooks` | 5 minutes | Pushes queued webhook deliveries. |
| `generate-alerts` | 30 minutes | Low stock, expired lots, purchases awaiting approval, customers over their credit limit. |
| `database-backup` | hourly | Takes a backup when the configured schedule is due, and prunes old ones. |
| `queue:prune-failed` | daily | Keeps the failed-job table from growing without bound. |

Laravel does not run these itself. Something outside has to call
`php artisan schedule:run` every minute, and the scheduler then decides what is
actually due. Without that, every job above silently never runs.

## What is registered on this machine

A Windows scheduled task named **CIMS scheduler**, repeating every minute, which
runs `scripts/schedule-run.vbs`.

The launcher is a VBScript rather than the artisan command directly because
Task Scheduler gives a command its own console window. Fired every minute that
is a black window flashing on the till all day, which on a machine facing
customers is not acceptable. `WScript.Shell.Run` with a window style of `0`
starts the process with no window at all.

To see it:

```bash
schtasks /query /tn "CIMS scheduler" /fo LIST /v
```

To run it once by hand:

```bash
schtasks /run /tn "CIMS scheduler"
```

To remove it:

```bash
schtasks /delete /tn "CIMS scheduler" /f
```

## Making it unattended

A task created without administrator rights has logon mode **Interactive only**:
it runs while the user who created it is logged on, and stops when they log out.
That is fine for a till someone sits at all day. It is not fine for a machine
that reboots to a login screen overnight and is still expected to post to the
ledger and take backups.

Running it as `SYSTEM` fixes that, and needs no stored password. Creating such a
task requires elevation, so run this once from an **administrator** PowerShell
prompt:

```bash
powershell -ExecutionPolicy Bypass -File "D:\Genova\PROJECTS\CIMS_FIXED_SOURCE\CIMS\scripts\install-scheduler-as-system.ps1"
```

It replaces the existing task, sets the same hardened options, and then waits for
the application's heartbeat to move before reporting success — because a task
that fires every minute and silently fails to reach PHP looks exactly like one
that works.

Before registering anything it checks the one thing that most often breaks a
SYSTEM task: whether the project sits on a mapped network drive. SYSTEM cannot
see mapped drives, so such a task would fail every minute with nothing obvious to
show for it. On this machine the project is on a local disk, and SYSTEM already
has full control of it.

## If the paths change

`scripts/schedule-run.vbs` has the PHP binary and the project directory written
into it. It checks both exist and exits quietly if either has moved, rather than
putting an error dialog on the till every minute — so if scheduled work stops
happening, check those two paths first.

## How to tell whether it is working

`storage/framework/scheduler-heartbeat` holds the time the scheduler last ran.
It is rewritten every minute, so if it is more than a minute or two behind the
clock, the scheduler is not being called:

```bash
type storage\framework\scheduler-heartbeat
```

This exists because the task itself cannot tell you. `schedule-run.vbs` starts
PHP and returns immediately, so Task Scheduler records a result of 0 whether or
not PHP did anything at all.

The accounting screen (Settings → Accounting → Journal) is the other side of it:
it says either *The books are up to date* or how many documents are waiting, with
the time posting last ran.
