' Jalankan tools/pull-santesrv1.cmd tanpa jendela console (untuk Windows Task Scheduler).
' Pasang sebagai action task: wscript.exe "<path>\tools\pull-santesrv1-hidden.vbs"
' Exit code cmd/php diteruskan ke Task Scheduler (LastTaskResult), jadi 0 = tarikan sukses.
Dim fso, sh, dir, cmd, rc
Set fso = CreateObject("Scripting.FileSystemObject")
Set sh = CreateObject("WScript.Shell")
dir = fso.GetParentFolderName(WScript.ScriptFullName)
sh.CurrentDirectory = fso.GetParentFolderName(dir)
cmd = "cmd /c """ & dir & "\pull-santesrv1.cmd"""
rc = sh.Run(cmd, 0, True)
WScript.Quit rc
