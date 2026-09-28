using System.Drawing;
using System.Drawing.Drawing2D;
using System.Drawing.Imaging;
using System.Runtime.InteropServices;
using System.Windows;

namespace TrackerAgent;

/// <summary>
/// Builds the Windows shell icon for the tray from the embedded MITO mark.
/// </summary>
/// <remarks>
/// <see cref="System.Windows.Forms.NotifyIcon"/> needs a
/// <see cref="System.Drawing.Icon"/>, but the only asset in the project is a
/// PNG. The tray used to fall back to <c>SystemIcons.Information</c>, which is
/// the generic blue Windows "i" — so a deployed agent was visually
/// indistinguishable from any other background app.
///
/// Sizing matters: the shell asks for 16x16 on a 100% display and 32x32 at
/// 150%+. Rendering the 256x256 source straight into an HICON produces a
/// mushy, over-large icon in the notification area, so the bitmap is drawn at
/// exactly the shell's size with high-quality interpolation.
/// </remarks>
public static class MitoIcon
{
    [DllImport("user32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool DestroyIcon(IntPtr handle);

    /// <summary>MITO tray icon, or null when the embedded resource is missing.</summary>
    public static Icon? Create(int size = 32)
    {
        Icon? icon = null;
        Bitmap? bitmap = null;
        IntPtr handle = IntPtr.Zero;

        try
        {
            // Fully qualified: this project also references WinForms, whose
            // Application clashes with WPF's. StreamResourceInfo is not
            // IDisposable, so the stream is what gets disposed.
            var resource = System.Windows.Application.GetResourceStream(
                new Uri("pack://application:,,,/mito-icon.png"));

            if (resource is null)
            {
                return null;
            }

            using var stream = resource.Stream;
            using var source = new Bitmap(stream);

            bitmap = new Bitmap(size, size, PixelFormat.Format32bppArgb);
            bitmap.SetResolution(96, 96);

            using (var graphics = Graphics.FromImage(bitmap))
            {
                graphics.InterpolationMode = InterpolationMode.HighQualityBicubic;
                graphics.PixelOffsetMode = PixelOffsetMode.HighQuality;
                graphics.SmoothingMode = SmoothingMode.HighQuality;
                graphics.Clear(Color.Transparent);
                graphics.DrawImage(source, new Rectangle(0, 0, size, size));
            }

            handle = bitmap.GetHicon();

            if (handle == IntPtr.Zero)
            {
                return null;
            }

            // Clone, then drop the temporary handle: GetHicon's copy is not
            // managed and would leak a GDI object on every agent start.
            using (var temp = Icon.FromHandle(handle))
            {
                icon = (Icon)temp.Clone();
            }
        }
        catch
        {
            // A missing icon must never stop the agent from running.
            return null;
        }
        finally
        {
            if (handle != IntPtr.Zero)
            {
                DestroyIcon(handle);
            }

            bitmap?.Dispose();
        }

        return icon;
    }
}
