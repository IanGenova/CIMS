' Runs Laravel's scheduler once, without putting a console window on screen.
'
' Windows Task Scheduler runs a command in its own console. Fired every minute,
' that is a black window flashing on the till all day, which on a machine facing
' customers is not acceptable. WScript.Shell.Run with a window style of 0 starts
' the process with no window at all.
'
' The scheduler itself decides what is actually due; this only has to call it.
' It is deliberately fire-and-forget (the third argument is False) so a slow job
' cannot hold the next minute's run behind it.

Option Explicit

Dim shell, fso, php, project, command

php = "C:\wamp64\bin\php\php8.0.30\php.exe"
project = "D:\Genova\PROJECTS\CIMS_FIXED_SOURCE\CIMS"

Set fso = CreateObject("Scripting.FileSystemObject")

' Fail quietly rather than pop an error dialog on the till every minute if the
' paths ever move; the absence of scheduled work will show up in the app itself.
If Not fso.FileExists(php) Then WScript.Quit 1
If Not fso.FolderExists(project) Then WScript.Quit 1

Set shell = CreateObject("WScript.Shell")
shell.CurrentDirectory = project

command = """" & php & """ artisan schedule:run"
shell.Run command, 0, False
