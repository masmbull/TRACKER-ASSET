using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using Brushes = System.Windows.Media.Brushes;
using Color = System.Windows.Media.Color;
using FontFamily = System.Windows.Media.FontFamily;
using Orientation = System.Windows.Controls.Orientation;
using HorizontalAlignment = System.Windows.HorizontalAlignment;
using Button = System.Windows.Controls.Button;
using Border = System.Windows.Controls.Border;
using StackPanel = System.Windows.Controls.StackPanel;
using TextBlock = System.Windows.Controls.TextBlock;
using Cursors = System.Windows.Input.Cursors;

namespace TrackerAgent;

internal static class InstallerDialogs
{
    /// <summary>
    /// A two-button question in the wizard's own styling.
    /// </summary>
    /// <remarks>
    /// A MessageBox would match neither the light theme nor the language of the
    /// rest of the flow, and its buttons cannot be labelled with the consequences.
    /// This is deliberately small: the wizard is already modal, so the question
    /// only has to pause it.
    /// </remarks>
    internal static bool Ask(Window owner, string title, string body, string primary, string secondary)
    {
        var window = new Window
        {
            Title = title,
            Owner = owner,
            WindowStartupLocation = WindowStartupLocation.CenterOwner,
            ResizeMode = ResizeMode.NoResize,
            SizeToContent = SizeToContent.Height,
            Width = 480,
            FontFamily = new FontFamily("Segoe UI"),
            Background = new SolidColorBrush(Color.FromRgb(0xF3, 0xF4, 0xF6)),
            WindowStyle = WindowStyle.SingleBorderWindow,
        };

        var card = new Border
        {
            Background = Brushes.White,
            BorderBrush = new SolidColorBrush(Color.FromRgb(0xE4, 0xE7, 0xEC)),
            BorderThickness = new Thickness(1),
            CornerRadius = new CornerRadius(8),
            Margin = new Thickness(16),
            Padding = new Thickness(20),
        };

        var stack = new StackPanel();

        stack.Children.Add(new TextBlock
        {
            Text = title,
            FontSize = 16,
            FontWeight = FontWeights.SemiBold,
            Foreground = new SolidColorBrush(Color.FromRgb(0x10, 0x18, 0x28)),
            Margin = new Thickness(0, 0, 0, 8),
        });

        stack.Children.Add(new TextBlock
        {
            Text = body,
            FontSize = 12.5,
            Foreground = new SolidColorBrush(Color.FromRgb(0x34, 0x40, 0x54)),
            TextWrapping = TextWrapping.Wrap,
            LineHeight = 19,
        });

        var answer = false;

        var buttons = new StackPanel
        {
            Orientation = Orientation.Horizontal,
            HorizontalAlignment = HorizontalAlignment.Right,
            Margin = new Thickness(0, 18, 0, 0),
        };

        var useExisting = new Button
        {
            Content = secondary,
            Height = 40,
            MinWidth = 128,
            Padding = new Thickness(20, 0, 20, 0),
            FontSize = 13,
            FontWeight = FontWeights.SemiBold,
            Foreground = new SolidColorBrush(Color.FromRgb(0x34, 0x40, 0x54)),
            Background = Brushes.White,
            BorderBrush = new SolidColorBrush(Color.FromRgb(0xD0, 0xD5, 0xDD)),
            BorderThickness = new Thickness(1),
            Cursor = Cursors.Hand,
            Margin = new Thickness(0, 0, 10, 0),
        };

        useExisting.Click += (_, _) =>
        {
            answer = false;
            window.DialogResult = true;
        };

        var accept = new Button
        {
            Content = primary,
            Height = 40,
            MinWidth = 150,
            Padding = new Thickness(20, 0, 20, 0),
            FontSize = 13,
            FontWeight = FontWeights.SemiBold,
            Foreground = Brushes.White,
            Background = new SolidColorBrush(Color.FromRgb(0xDC, 0x26, 0x26)),
            BorderThickness = new Thickness(0),
            Cursor = Cursors.Hand,
        };

        accept.Click += (_, _) =>
        {
            answer = true;
            window.DialogResult = true;
        };

        buttons.Children.Add(useExisting);
        buttons.Children.Add(accept);
        stack.Children.Add(buttons);

        card.Child = stack;
        window.Content = card;

        window.ShowDialog();

        return answer;
    }

}




