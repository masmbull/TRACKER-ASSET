using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;
using System.Text.RegularExpressions;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Media.Animation;
using ComboBox = System.Windows.Controls.ComboBox;
using Color = System.Windows.Media.Color;
using FontFamily = System.Windows.Media.FontFamily;
using Orientation = System.Windows.Controls.Orientation;
using TextBox = System.Windows.Controls.TextBox;

namespace TrackerAgent;

public sealed record RegistrationChoice(int Id, string Name, string Code = "")
{
    public override string ToString() => Name;
}

public sealed class RegistrationLookups
{
    public List<RegistrationChoice> Companies { get; init; } = [];
    public List<RegistrationChoice> Categories { get; init; } = [];
    public List<RegistrationChoice> Locations { get; init; } = [];
    public List<RegistrationChoice> Statuses { get; init; } = [];
    public List<RegistrationChoice> Users { get; init; } = [];
}

public sealed record AgentRegistrationFields(
    int CompanyId,
    int CategoryId,
    int LocationId,
    int StatusId,
    int? CurrentUserId,
    int? ItPicId,
    int PurchaseYear,
    string? Vendor,
    decimal? PurchasePrice);
/// <summary>
/// First-install form for the 10 asset fields from the Fleet promotion form.
/// </summary>
/// <remarks>
/// The layout lives in AgentRegistrationWindow.xaml, not in this file. It used to
/// be built here with its own dark palette, so it drifted from the installer that
/// opened it and looked like a separate application; sharing the wizard's light
/// theme now means the two can only diverge if this file starts styling things
/// again. This file builds the fields and reads the answers - nothing else.
/// </remarks>
public sealed partial class AgentRegistrationWindow : Window
{
    private readonly RegistrationLookups _lookups;
    private readonly Dictionary<string, ComboBox> _selects = [];
    private TextBox _purchaseYear = null!;
    private TextBox _vendor = null!;
    private TextBox _purchasePrice = null!;
    
    public AgentRegistrationFields? Fields { get; private set; }

    public AgentRegistrationWindow(RegistrationLookups lookups)
    {
        _lookups = lookups;

        InitializeComponent();

        BuildForm();
        UpdateCodePreview();
    }

    /// <summary>
    /// Lays out the ten fields in two columns, in the order the promotion form
    /// uses so an operator moving between the two does not re-learn it.
    /// </summary>
    /// <remarks>
    /// FormPanel is a two-column Grid, not a StackPanel. It was a StackPanel when
    /// this form was first rebuilt, which silently stacked every field into one
    /// column and pushed the last few below the fold - the layout asked for
    /// row/column but had nowhere to put them.
    /// </remarks>
    private void BuildForm()
    {
        var fields = new (string Label, string Icon, Func<UIElement> Build)[]
        {
            ("Perusahaan *", "\uE8C9", () => MakeSelect("company_id", "Pilih perusahaan", _lookups.Companies)),
            ("Kategori *", "\uE8EC", () => MakeSelect("category_id", "Pilih kategori", _lookups.Categories)),
            ("Lokasi *", "\uE707", () => MakeSelect("location_id", "Pilih lokasi", _lookups.Locations)),
            ("Status aset *", "\uE9D5", () => MakeSelect("asset_status_id", "Pilih status", _lookups.Statuses)),
            ("Pemakai", "\uE77B", () => MakeSelect("current_user_id", "Belum ditentukan", _lookups.Users, optional: true)),
            ("IT PIC", "\uE779", () => MakeSelect("admin_it_pic", "Pilih IT PIC (opsional)", _lookups.Users, optional: true)),
            ("Tahun pembelian *", "\uE787", () => _purchaseYear = MakeTextBox(
                DateTime.Now.Year.ToString(CultureInfo.InvariantCulture),
                placeholder: "Contoh: 2026", icon: "\uE787", maxLength: 4)),
            ("Vendor", "\uE719", () => _vendor = MakeTextBox(placeholder: "Nama vendor (opsional)", icon: "\uE719")),
            ("Harga pembelian", "\uE8C7", () => _purchasePrice = MakeTextBox(placeholder: "0", icon: "\uE716", maxLength: 18)),
        };

        // Each field is two cells wide in a two-column grid, so the pairs are laid
        // out row by row: 1-2, 3-4, and so on.
        for (var i = 0; i < fields.Length; i++)
        {
            var row = i / 2;
            var column = i % 2;

            while (FormPanel.RowDefinitions.Count <= row)
            {
                FormPanel.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto });
            }

            var index = i + 1;
            AddField(row, column * 2, $"{index} · {fields[i].Label}", fields[i].Icon, fields[i].Build());
        }

        foreach (var key in new[] { "company_id", "category_id" })
        {
            _selects[key].SelectionChanged += (_, _) => UpdateCodePreview();
        }

        _purchaseYear.TextChanged += (_, _) => UpdateCodePreview();

        WireRupiahInput();

        AnimateIn();
    }

    /// <summary>Rupiah grouping: dot between thousands, no decimals.</summary>
    private static readonly CultureInfo RupiahFormat = CreateRupiahFormat();

    private static CultureInfo CreateRupiahFormat()
    {
        var culture = (CultureInfo)CultureInfo.InvariantCulture.Clone();

        culture.NumberFormat.NumberGroupSeparator = ".";
        culture.NumberFormat.NumberDecimalSeparator = ",";
        culture.NumberFormat.NumberGroupSizes = [3];

        return culture;
    }

    /// <summary>
    /// Formats the purchase price as rupiah while it is typed.
    /// </summary>
    /// <remarks>
    /// The field accepts digits only and regroups them with dots as the operator
    /// types, so 15000000 reads 15.000.000 - the way a price is written on a
    /// purchase order. Parsing uses the same invariant digits underneath, so a
    /// stored value is never a string with formatting in it.
    ///
    /// The caret is restored by digit count rather than character index: grouping
    /// inserts dots, and a raw index would jump the cursor to the wrong place after
    /// every fourth digit.
    /// </remarks>
    private void WireRupiahInput()
    {
        // The "Rp" prefix is added by AddField, inside the same cell as the field,
        // so it is never part of the value. Only the padding and formatting live
        // here: the text starts after the prefix and is regrouped as it is typed.
        _purchasePrice.Padding = new Thickness(64, 0, 11, 0);
        _purchasePrice.TextChanged += (_, _) =>
        {
            var digits = new string(_purchasePrice.Text.Where(char.IsDigit).ToArray());

            if (digits.Length > 15)
            {
                digits = digits[..15];
            }

            // Indonesian grouping: a dot between thousands, which is how a rupiah
            // price is written here. InvariantCulture was a mistake - it groups
            // with commas, so a price read "15,000,000".
            var formatted = digits.Length == 0
                ? string.Empty
                : long.Parse(digits, CultureInfo.InvariantCulture).ToString("#,##0", RupiahFormat);

            if (formatted == _purchasePrice.Text)
            {
                return;
            }

            // Count digits before the caret, then put the caret after that many
            // digits in the new text.
            var caret = _purchasePrice.CaretIndex;
            var digitsBefore = _purchasePrice.Text.Take(Math.Min(caret, _purchasePrice.Text.Length))
                .Count(char.IsDigit);

            _purchasePrice.Text = formatted;

            var seen = 0;
            var target = formatted.Length;
            for (var i = 0; i < formatted.Length; i++)
            {
                if (seen == digitsBefore)
                {
                    target = i;
                    break;
                }

                if (char.IsDigit(formatted[i]))
                {
                    seen++;
                }
            }

            _purchasePrice.CaretIndex = target;
        };
    }

    /// <summary>Fades and lifts the form in, so the window does not just appear.</summary>
    private void AnimateIn()
    {
        var translate = new TranslateTransform(0, 8);

        FormPanel.RenderTransform = translate;
        FormPanel.Opacity = 0;

        var duration = TimeSpan.FromMilliseconds(220);
        var ease = new CubicEase { EasingMode = EasingMode.EaseOut };

        FormPanel.BeginAnimation(OpacityProperty, new DoubleAnimation(1, duration) { EasingFunction = ease });
        translate.BeginAnimation(TranslateTransform.YProperty, new DoubleAnimation(0, duration) { EasingFunction = ease });
    }

    /// <summary>One label + control pair, placed in the two-column grid.</summary>
    private void AddField(int row, int column, string label, string iconGlyph, UIElement control)
    {
        var field = new StackPanel { Margin = new Thickness(0, 0, 0, 16) };

        // Label row: a numbered chip, a small glyph, then the text - the same
        // pattern the setup wizard uses for its checklist, so the two read as one
        // product.
        var labelRow = new StackPanel { Orientation = Orientation.Horizontal, Margin = new Thickness(0, 0, 0, 7) };

        labelRow.Children.Add(new TextBlock
        {
            Text = iconGlyph,
            FontFamily = new FontFamily("Segoe MDL2 Assets"),
            FontSize = 12,
            Foreground = new SolidColorBrush(Color.FromRgb(0x98, 0xA2, 0xB3)),
            VerticalAlignment = VerticalAlignment.Center,
            Margin = new Thickness(0, 0, 7, 0),
        });

        labelRow.Children.Add(new TextBlock
        {
            Text = label,
            FontSize = 11.5,
            FontWeight = FontWeights.SemiBold,
            Foreground = new SolidColorBrush(Color.FromRgb(0x34, 0x40, 0x54)),
            VerticalAlignment = VerticalAlignment.Center,
        });

        field.Children.Add(labelRow);

        if (ReferenceEquals(control, _purchasePrice))
        {
            // The "Rp" prefix is drawn inside the same cell as the field instead of
            // being typed into it, so the value stays a number.
            var overlay = new Grid();

            overlay.Children.Add(control);
            overlay.Children.Add(new TextBlock
            {
                Text = "Rp",
                FontSize = 13,
                FontWeight = FontWeights.SemiBold,
                Foreground = new SolidColorBrush(Color.FromRgb(0x34, 0x40, 0x54)),
                Margin = new Thickness(34, 0, 0, 0),
                VerticalAlignment = VerticalAlignment.Center,
                IsHitTestVisible = false,
            });

            field.Children.Add(overlay);
        }
        else
        {
            field.Children.Add(control);
        }

        Grid.SetRow(field, row);
        Grid.SetColumn(field, column);

        FormPanel.Children.Add(field);
    }

    private ComboBox MakeSelect(
        string key,
        string placeholder,
        IReadOnlyList<RegistrationChoice> choices,
        bool optional = false)
    {
        var combo = new ComboBox
        {
            Style = (Style)FindResource("SelectStyle"),
            // The template reads Tag as the leading glyph, so the icon arrives
            // without a second column of markup in the form.
            Tag = optional ? "\uE7B8" : "\uE73E",
        };

        combo.Items.Add(new ComboBoxItem
        {
            Content = placeholder,
            Tag = null,
            Foreground = new SolidColorBrush(Color.FromRgb(0x98, 0xA2, 0xB3)),
            FontStyle = FontStyles.Italic,
        });

        foreach (var choice in choices)
        {
            combo.Items.Add(choice);
        }

        combo.SelectedIndex = 0;
        _selects[key] = combo;

        return combo;
    }

    private TextBox MakeTextBox(string value = "", string placeholder = "", string icon = "", int maxLength = 0)
    {
        var box = new TextBox
        {
            Text = value,
            Style = (Style)FindResource("FieldTextStyle"),
            // The template draws both of these: Tag is the leading glyph, ToolTip
            // becomes the placeholder shown only while the box is empty.
            Tag = icon,
            ToolTip = placeholder,
        };

        if (maxLength > 0)
        {
            box.MaxLength = maxLength;
        }

        return box;
    }


    private void UpdateCodePreview()
    {
        var company = _selects["company_id"].SelectedItem as RegistrationChoice;
        var category = _selects["category_id"].SelectedItem as RegistrationChoice;
        var year = _purchaseYear.Text.Trim();

        if (company is null || category is null || year.Length != 4 || !int.TryParse(year, out _))
        {
            AssetCodeText.Text = "Pilih perusahaan, kategori, dan tahun pembelian";
            return;
        }

        AssetCodeText.Text =
            $"{company.Code}-{CategoryCode(category.Name)}-{year}-####  ·  nomor final dibuat server";
    }

    private static string CategoryCode(string name)
    {
        var normalized = name.Trim().ToLowerInvariant();

        var known = normalized switch
        {
            "laptop" => "LAP",
            "pc" or "desktop" => "PC",
            "server" => "SRV",
            "printer" => "PRN",
            "monitor" => "MON",
            "network" => "NET",
            "cctv" => "CCTV",
            "ups" => "UPS",
            "storage" => "STO",
            _ => null,
        };

        if (known is not null)
        {
            return known;
        }

        var words = Regex.Split(name.Trim().ToUpperInvariant(), "[^A-Z0-9]+", RegexOptions.CultureInvariant)
            .Where(word => word.Length > 0)
            .Take(4)
            .ToArray();

        return words.Length > 1
            ? string.Concat(words.Select(word => word[0]))
            : (words.FirstOrDefault() ?? string.Empty)[..Math.Min(4, words.FirstOrDefault()?.Length ?? 0)];
    }

    private void CancelButton_Click(object sender, RoutedEventArgs e)
    {
        DialogResult = false;
        Close();
    }

    private void SubmitButton_Click(object sender, RoutedEventArgs e) => Submit();

    private void Submit()
    {
        ValidationText.Text = string.Empty;

        RegistrationChoice? ReadRequired(string key, string label)
        {
            if (_selects[key].SelectedItem is RegistrationChoice selected)
            {
                return selected;
            }

            ValidationText.Text = $"Pilih {label}.";
            return null;
        }

        var company = ReadRequired("company_id", "perusahaan");
        var category = ReadRequired("category_id", "kategori");
        var location = ReadRequired("location_id", "lokasi");
        var status = ReadRequired("asset_status_id", "status aset");

        if (company is null || category is null || location is null || status is null)
        {
            return;
        }

        if (!int.TryParse(_purchaseYear.Text.Trim(), out var year) ||
            year < 1900 || year > DateTime.Now.Year + 2)
        {
            ValidationText.Text = "Tahun pembelian tidak valid.";
            return;
        }

        decimal? price = null;

        if (!string.IsNullOrWhiteSpace(_purchasePrice.Text))
        {
            // The field shows "15.000.000", so the digits are what actually carry
            // the value. Parsing the formatted string with the current culture
            // would read the dots as decimal points in some locales and as grouping
            // in others - the same price recorded 1000x wrong depending on the
            // machine's regional settings.
            var digits = new string(_purchasePrice.Text.Where(char.IsDigit).ToArray());

            if (!decimal.TryParse(digits, NumberStyles.None,
                    CultureInfo.InvariantCulture, out var parsed) || parsed < 0)
            {

                ValidationText.Text = "Harga pembelian harus berupa angka nol atau lebih.";
                return;
            }

            price = parsed;
        }

        Fields = new AgentRegistrationFields(
            company.Id,
            category.Id,
            location.Id,
            status.Id,
            (_selects["current_user_id"].SelectedItem as RegistrationChoice)?.Id,
            (_selects["admin_it_pic"].SelectedItem as RegistrationChoice)?.Id,
            year,
            NullIfBlank(_vendor.Text),
            price);

        DialogResult = true;
        Close();
    }

    private static string? NullIfBlank(string value) =>
        string.IsNullOrWhiteSpace(value) ? null : value.Trim();
}








