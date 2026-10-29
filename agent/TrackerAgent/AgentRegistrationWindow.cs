using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;
using System.Text.RegularExpressions;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using ComboBox = System.Windows.Controls.ComboBox;
using Color = System.Windows.Media.Color;
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
    private TextBlock _assetCodePreview = null!;

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
    private void BuildForm()
    {
        _assetCodePreview = new TextBlock
        {
            Text = "Pilih perusahaan, kategori, dan tahun pembelian",
            FontSize = 13,
            FontWeight = FontWeights.SemiBold,
            Foreground = new SolidColorBrush(Color.FromRgb(0xB4, 0x23, 0x18)),
            TextWrapping = TextWrapping.Wrap,
            VerticalAlignment = VerticalAlignment.Center,
        };

        AddField(0, "1 · Kode aset (dibuat otomatis)", MakePreview(_assetCodePreview));

        AddField(1, "2 · Perusahaan *", MakeSelect("company_id", "Pilih perusahaan", _lookups.Companies));

        AddField(2, "3 · Kategori *", MakeSelect("category_id", "Pilih kategori", _lookups.Categories));

        AddField(3, "4 · Lokasi *", MakeSelect("location_id", "Pilih lokasi", _lookups.Locations));

        AddField(4, "5 · Status aset *", MakeSelect("asset_status_id", "Pilih status", _lookups.Statuses));

        AddField(5, "6 · Pemakai", MakeSelect("current_user_id", "Belum ditentukan", _lookups.Users, optional: true));

        AddField(6, "7 · IT PIC", MakeSelect("admin_it_pic", "Pilih IT PIC (opsional)", _lookups.Users, optional: true));

        _purchaseYear = MakeTextBox(DateTime.Now.Year.ToString(CultureInfo.InvariantCulture));
        _purchaseYear.MaxLength = 4;
        AddField(7, "8 · Tahun pembelian *", _purchaseYear);

        _vendor = MakeTextBox();
        AddField(8, "9 · Vendor", _vendor);

        _purchasePrice = MakeTextBox();
        AddField(9, "10 · Harga pembelian", _purchasePrice);

        foreach (var key in new[] { "company_id", "category_id" })
        {
            _selects[key].SelectionChanged += (_, _) => UpdateCodePreview();
        }

        _purchaseYear.TextChanged += (_, _) => UpdateCodePreview();
    }

    /// <summary>One label + control pair, placed in the two-column grid.</summary>
    private void AddField(int index, string label, UIElement control)
    {
        var row = index / 2;
        var column = index % 2;

        var field = new StackPanel
        {
            Margin = new Thickness(column == 0 ? 0 : 9, 0, column == 0 ? 9 : 0, 14),
        };

        field.Children.Add(new TextBlock
        {
            Text = label,
            FontSize = 11.5,
            FontWeight = FontWeights.SemiBold,
            Foreground = new SolidColorBrush(Color.FromRgb(0x66, 0x70, 0x85)),
            Margin = new Thickness(0, 0, 0, 6),
        });

        field.Children.Add(control);

        FormPanel.Children.Add(field);
    }

    private static Border MakePreview(TextBlock text) => new()
    {
        Padding = new Thickness(11, 10, 11, 10),
        CornerRadius = new CornerRadius(6),
        BorderThickness = new Thickness(1),
        BorderBrush = new SolidColorBrush(Color.FromRgb(0xE4, 0xE7, 0xEC)),
        Background = new SolidColorBrush(Color.FromRgb(0xF9, 0xFA, 0xFB)),
        Child = text,
    };

    private ComboBox MakeSelect(
        string key,
        string placeholder,
        IReadOnlyList<RegistrationChoice> choices,
        bool optional = false)
    {
        var combo = new ComboBox
        {
            Style = (Style)FindResource("SelectStyle"),
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

    private TextBox MakeTextBox(string value = "") => new()
    {
        Text = value,
        Style = (Style)FindResource("FieldTextStyle"),
    };

    private void UpdateCodePreview()
    {
        var company = _selects["company_id"].SelectedItem as RegistrationChoice;
        var category = _selects["category_id"].SelectedItem as RegistrationChoice;
        var year = _purchaseYear.Text.Trim();

        if (company is null || category is null || year.Length != 4 || !int.TryParse(year, out _))
        {
            _assetCodePreview.Text = "Pilih perusahaan, kategori, dan tahun pembelian";
            return;
        }

        _assetCodePreview.Text =
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
            if (!decimal.TryParse(_purchasePrice.Text.Trim(), NumberStyles.Number,
                    CultureInfo.CurrentCulture, out var parsed) || parsed < 0)
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




