using System;
using System.IO;
using System.Security.AccessControl;
using System.Security.Principal;

namespace TrackerAgent;

/// <summary>
/// Makes the install folder unremovable by the logged-in standard user.
/// </summary>
/// <remarks>
/// The install lives in %LOCALAPPDATA%, which belongs to the user: without a
/// deny rule, deleting the folder is two clicks in Explorer, and an agent that
/// can be deleted by the person it reports on is not much of an agent.
///
/// The rule is a *deny* on delete, not a removal of read/execute: the tray must
/// still run from that folder as the user, and denying DeleteSubdirectoriesAndFiles
/// plus Delete is exactly what stops a delete while leaving execution intact.
/// Administrators are denied too, which sounds hostile but is the point - a deny
/// ACE applies to everyone it names, so an admin who genuinely needs to remove it
/// clears the rule first (a deliberate act, not a slip). The uninstall path does
/// that itself via <see cref="Clear"/>.
///
/// Applied best-effort: on a machine with a hardened or oddly permissioned profile
/// the agent still has to install, so a failure is logged and reporting continues.
/// </remarks>
internal static class InstallFolderProtection
{
    private const string RuleName = "TRACKER-ASSET protect install folder";

    /// <summary>Deny delete on the folder and everything in it for a principal.</summary>
    internal static bool Protect(string folderPath, out string message)
    {
        try
        {
            if (!Directory.Exists(folderPath))
            {
                message = "Folder instalasi belum ada.";
                return false;
            }

            var user = WindowsIdentity.GetCurrent().User;

            if (user is null)
            {
                message = "Tidak bisa menentukan identitas pengguna.";
                return false;
            }

            // Users group, not just this account: a local profile can be recreated
            // and then the sid in the rule means nothing, but "Users" always covers
            // whoever logs in next.
            var users = new SecurityIdentifier(WellKnownSidType.BuiltinUsersSid, null);

            var security = new DirectorySecurity();

            security.AddAccessRule(new FileSystemAccessRule(
                users,
                FileSystemRights.Delete | FileSystemRights.DeleteSubdirectoriesAndFiles,
                InheritanceFlags.ContainerInherit | InheritanceFlags.ObjectInherit,
                PropagationFlags.None,
                AccessControlType.Deny));

            security.AddAccessRule(new FileSystemAccessRule(
                user,
                FileSystemRights.Delete | FileSystemRights.DeleteSubdirectoriesAndFiles,
                InheritanceFlags.ContainerInherit | InheritanceFlags.ObjectInherit,
                PropagationFlags.None,
                AccessControlType.Deny));

            security.SetAccessRuleProtection(isProtected: false, preserveInheritance: true);

            new DirectoryInfo(folderPath).SetAccessControl(security);

            message = "Folder instalasi dilindungi dari penghapusan.";
            return true;
        }
        catch (Exception ex)
        {
            message = "Proteksi folder gagal: " + ex.Message;
            AgentLog.Write($"Install folder protection failed: {ex}");
            return false;
        }
    }

    /// <summary>
    /// Removes the deny rules so the folder can be deleted again.
    /// </summary>
    /// <remarks>
    /// This is what lets an operator uninstall deliberately. Without it the deny
    /// rule would make the uninstall path fail with "access denied" on the very
    /// machine it is supposed to clean up.
    /// </remarks>
    internal static bool Clear(string folderPath, out string message)
    {
        try
        {
            if (!Directory.Exists(folderPath))
            {
                message = "Folder instalasi tidak ada.";
                return true;
            }

            var dir = new DirectoryInfo(folderPath);
            var security = dir.GetAccessControl();

            var removed = 0;

            foreach (FileSystemAccessRule rule in security.GetAccessRules(true, false, typeof(SecurityIdentifier)))
            {
                if (rule.AccessControlType == AccessControlType.Deny)
                {
                    security.RemoveAccessRule(rule);
                    removed++;
                }
            }

            dir.SetAccessControl(security);

            message = removed > 0 ? "Proteksi folder dilepas." : "Tidak ada proteksi folder.";
            return true;
        }
        catch (Exception ex)
        {
            message = "Gagal melepas proteksi folder: " + ex.Message;
            AgentLog.Write($"Install folder protection clear failed: {ex}");
            return false;
        }
    }
}
