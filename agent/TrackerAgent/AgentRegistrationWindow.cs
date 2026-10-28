using System.Globalization;
using System.Text.RegularExpressions;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using Button = System.Windows.Controls.Button;
using ComboBox = System.Windows.Controls.ComboBox;
using Color = System.Windows.Media.Color;
using FontFamily = System.Windows.Media.FontFamily;
using Brushes = System.Windows.Media.Brushes;
using TextBox = System.Windows.Controls.TextBox;

namespace TrackerAgent;

internal sealed record RegistrationChoice(int Id, string Name, string Code = "")
{
    public override string ToString() => Name;
}

internal sealed class RegistrationLookups
{
    public List<RegistrationChoice> Companies { get; init; } = [];
    public List<RegistrationChoice> Categories { get; init; } = [];
    public List<RegistrationChoice> Locations { get; init; } = [];
    public List<RegistrationChoice> Statuses { get; init; } = [];
    public List<RegistrationChoice> Users { get; init; } = [];
}

internal sealed record AgentRegistrationFields(
    int CompanyId,
    int CategoryId,
    int LocationId,
    int StatusId,
    int? CurrentUserId,
    int? ItPicId,
    int PurchaseYear,
    string? Vendor,
    decimal? PurchasePrice);

/// <summary>First-install form for the 10 fields from the Fleet promotion form.</summary>
internal sealed class AgentRegistrationWindow : Window
{
    private static readonly Color Surface = Color.FromRgb(0x18, 0x22, 0x30);
    private static readonly Color FieldSurface = Color.FromRgb(0x10, 0x18, 0x25);
    private static readonly Color BorderColor = Color.FromRgb(0x34, 0x41, 0x54);
    private static readonly Color Muted = Color.FromRgb(0xA8, 0xB2, 0xC2);
    private static readonly Color Accent = Color.FromRgb(0xF0, 0x44, 0x38);

    private readonly RegistrationLookups _lookups;
    private readonly Dictionary<string, ComboBox> _selects = [];
    private readonly TextBox _purchaseYear;
    private readonly TextBox _vendor;
    private readonly TextBox _purchasePrice;
    private readonly TextBlock _assetCodePreview;
    private readonly TextBlock _validation;

    public AgentRegistrationFields? Fields { get; private set; }

    public AgentRegistrationWindow(RegistrationLookups lookups)
    {
        _lookups = lookups;
        Title = "Daftarkan perangkat · TRACKER-ASSET";
        Width = 760;
        Height = 760;
        MinWidth = 700;
        MinHeight = 650;
        WindowStartupLocation = WindowStartupLocation.CenterOwner;
        ResizeMode = ResizeMode.CanResize;
        Background = new SolidColorBrush(Surface);
        Foreground = Brushes.White;
        FontFamily = new FontFamily("Segoe UI");

        var root = new Grid { Margin = new Thickness(24) };
        root.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto });
        root.RowDefinitions.Add(new RowDefinition { Height = new GridLength(1, GridUnitType.Star) });
        root.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto });

        var header = new Grid { Margin = new Thickness(0, 0, 0, 18) };
        header.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(48) });
        header.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        header.Children.Add(new Border
        {
            Width = 42,
            Height = 42,
            CornerRadius = new CornerRadius(12),
            Background = new SolidColorBrush(Accent),
            Child = new TextBlock
            {
                Text = "\uE8D7",
                FontFamily = new FontFamily("Segoe MDL2 Assets"),
                FontSize = 20,
                Foreground = Brushes.White,
                HorizontalAlignment = System.Windows.HorizontalAlignment.Center,
                VerticalAlignment = System.Windows.VerticalAlignment.Center,
            },
        });
        var heading = new StackPanel { Margin = new Thickness(12, 0, 0, 0), VerticalAlignment = VerticalAlignment.Center };
        heading.Children.Add(new TextBlock { Text = "Daftarkan perangkat baru", FontSize = 20, FontWeight = FontWeights.SemiBold });
        heading.Children.Add(new TextBlock
        {
            Text = "Lengkapi 10 data aset. Informasi perangkat dari agent akan ikut disimpan.",
            FontSize = 12,
            Foreground = new SolidColorBrush(Muted),
            Margin = new Thickness(0, 4, 0, 0),
        });
        Grid.SetColumn(heading, 1);
        header.Children.Add(heading);
        root.Children.Add(header);

        var scroll = new ScrollViewer { VerticalScrollBarVisibility = ScrollBarVisibility.Auto, HorizontalScrollBarVisibility = ScrollBarVisibility.Disabled };
        Grid.SetRow(scroll, 1);
        var form = new Grid();
        form.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        form.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        for (var i = 0; i < 5; i++) form.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto });

        _assetCodePreview = new TextBlock { Text = "Pilih perusahaan, kategori, dan tahun pembelian", FontSize = 13, FontWeight = FontWeights.SemiBold, Foreground = new SolidColorBrush(Color.FromRgb(0xFD, 0xB4, 0xAF)), TextWrapping = TextWrapping.Wrap };
        AddField(form, "1 · Kode aset (dibuat otomatis)", MakeReadOnlyPreview(_assetCodePreview), 0, 0);
        AddField(form, "2 · Perusahaan *", MakeSelect("company_id", "Pilih perusahaan", lookups.Companies), 0, 1);
        AddField(form, "3 · Kategori *", MakeSelect("category_id", "Pilih kategori", lookups.Categories), 1, 0);
        AddField(form, "4 · Lokasi *", MakeSelect("location_id", "Pilih lokasi", lookups.Locations), 1, 1);
        AddField(form, "5 · Status aset *", MakeSelect("asset_status_id", "Pilih status", lookups.Statuses), 2, 0);
        AddField(form, "6 · Pemakai", MakeSelect("current_user_id", "Belum ditentukan", lookups.Users, optional: true), 2, 1);
        AddField(form, "7 · IT PIC", MakeSelect("admin_it_pic", "Pilih IT PIC (opsional)", lookups.Users, optional: true), 3, 0);

        _purchaseYear = MakeTextBox(DateTime.Now.Year.ToString(CultureInfo.InvariantCulture));
        _purchaseYear.MaxLength = 4;
        AddField(form, "8 · Tahun pembelian *", _purchaseYear, 3, 1);
        _vendor = MakeTextBox();
        AddField(form, "9 · Vendor", _vendor, 4, 0);
        _purchasePrice = MakeTextBox();
        AddField(form, "10 · Harga pembelian", _purchasePrice, 4, 1);

        foreach (var key in new[] { "company_id", "category_id" }) _selects[key].SelectionChanged += (_, _) => UpdateCodePreview();
        _purchaseYear.TextChanged += (_, _) => UpdateCodePreview();
        scroll.Content = form;
        root.Children.Add(scroll);

        var footer = new Grid { Margin = new Thickness(0, 18, 0, 0) };
        footer.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        footer.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        _validation = new TextBlock { Foreground = new SolidColorBrush(Color.FromRgb(0xFD, 0xA2, 0x9B)), FontSize = 12, VerticalAlignment = VerticalAlignment.Center, TextWrapping = TextWrapping.Wrap, MaxWidth = 380 };
        footer.Children.Add(_validation);
        var buttons = new StackPanel { Orientation = System.Windows.Controls.Orientation.Horizontal, HorizontalAlignment = System.Windows.HorizontalAlignment.Right };
        var cancel = MakeButton("Batal", false);
        cancel.Click += (_, _) => { DialogResult = false; Close(); };
        var submit = MakeButton("Daftarkan perangkat", true);
        submit.Click += (_, _) => Submit();
        buttons.Children.Add(cancel);
        buttons.Children.Add(submit);
        Grid.SetColumn(buttons, 1);
        footer.Children.Add(buttons);
        Grid.SetRow(footer, 2);
        root.Children.Add(footer);

        Content = root;
        UpdateCodePreview();
    }

    private static Border MakeReadOnlyPreview(TextBlock text) => new()
    {
        Padding = new Thickness(11, 9, 11, 9),
        CornerRadius = new CornerRadius(8),
        BorderThickness = new Thickness(1),
        BorderBrush = new SolidColorBrush(BorderColor),
        Background = new SolidColorBrush(FieldSurface),
        Child = text,
    };

    private ComboBox MakeSelect(string key, string placeholder, IReadOnlyList<RegistrationChoice> choices, bool optional = false)
    {
        var combo = new ComboBox
        {
            Height = 38,
            Padding = new Thickness(8, 5, 8, 5),
            Background = new SolidColorBrush(FieldSurface),
            Foreground = Brushes.White,
            BorderBrush = new SolidColorBrush(BorderColor),
            BorderThickness = new Thickness(1),
            FontSize = 12,
        };
        combo.Items.Add(new ComboBoxItem { Content = placeholder, Tag = null, Foreground = new SolidColorBrush(Muted) });
        foreach (var choice in choices) combo.Items.Add(choice);
        combo.SelectedIndex = 0;
        _selects[key] = combo;
        return combo;
    }

    private static TextBox MakeTextBox(string value = "") => new()
    {
        Text = value,
        Height = 38,
        Padding = new Thickness(10, 8, 10, 8),
        Background = new SolidColorBrush(FieldSurface),
        Foreground = Brushes.White,
        BorderBrush = new SolidColorBrush(BorderColor),
        BorderThickness = new Thickness(1),
        FontSize = 12,
        VerticalContentAlignment = VerticalAlignment.Center,
    };

    private static void AddField(Grid grid, string label, UIElement control, int row, int column)
    {
        var field = new StackPanel { Margin = new Thickness(column == 0 ? 0 : 10, 0, column == 0 ? 10 : 0, 14) };
        field.Children.Add(new TextBlock { Text = label, FontSize = 11, FontWeight = FontWeights.SemiBold, Foreground = new SolidColorBrush(Muted), Margin = new Thickness(0, 0, 0, 6) });
        field.Children.Add(control);
        Grid.SetRow(field, row);
        Grid.SetColumn(field, column);
        grid.Children.Add(field);
    }

    private static Button MakeButton(string label, bool primary) => new()
    {
        Content = label,
        Height = 40,
        MinWidth = primary ? 170 : 82,
        Margin = new Thickness(10, 0, 0, 0),
        Padding = new Thickness(14, 0, 14, 0),
        Background = new SolidColorBrush(primary ? Accent : BorderColor),
        Foreground = Brushes.White,
        BorderThickness = new Thickness(0),
        FontWeight = FontWeights.SemiBold,
        Cursor = System.Windows.Input.Cursors.Hand,
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

        _assetCodePreview.Text = $"{company.Code}-{CategoryCode(category.Name)}-{year}-####  ·  nomor final dibuat server";
    }

    private static string CategoryCode(string name)
    {
        var normalized = name.Trim().ToLowerInvariant();
        var known = normalized switch
        {
            "laptop" => "LAP", "pc" or "desktop" => "PC", "server" => "SRV", "printer" => "PRN",
            "monitor" => "MON", "network" => "NET", "cctv" => "CCTV", "ups" => "UPS", "storage" => "STO",
            _ => null,
        };
        if (known is not null) return known;

        var words = Regex.Split(name.Trim().ToUpperInvariant(), "[^A-Z0-9]+", RegexOptions.CultureInvariant)
            .Where(word => word.Length > 0)
            .Take(4)
            .ToArray();
        return words.Length > 1
            ? string.Concat(words.Select(word => word[0]))
            : (words.FirstOrDefault() ?? string.Empty)[..Math.Min(4, words.FirstOrDefault()?.Length ?? 0)];
    }

    private void Submit()
    {
        RegistrationChoice? ReadRequired(string key, string label)
        {
            if (_selects[key].SelectedItem is RegistrationChoice selected) return selected;
            _validation.Text = $"Pilih {label}.";
            return null;
        }

        var company = ReadRequired("company_id", "perusahaan");
        var category = ReadRequired("category_id", "kategori");
        var location = ReadRequired("location_id", "lokasi");
        var status = ReadRequired("asset_status_id", "status aset");
        if (company is null || category is null || location is null || status is null) return;
        if (!int.TryParse(_purchaseYear.Text.Trim(), out var year) || year < 1900 || year > DateTime.Now.Year + 2)
        {
            _validation.Text = "Tahun pembelian tidak valid.";
            return;
        }

        decimal? price = null;
        if (!string.IsNullOrWhiteSpace(_purchasePrice.Text))
        {
            if (!decimal.TryParse(_purchasePrice.Text.Trim(), NumberStyles.Number, CultureInfo.CurrentCulture, out var parsed) || parsed < 0)
            {
                _validation.Text = "Harga pembelian harus berupa angka nol atau lebih.";
                return;
            }
            price = parsed;
        }

        Fields = new AgentRegistrationFields(
            company.Id, category.Id, location.Id, status.Id,
            (_selects["current_user_id"].SelectedItem as RegistrationChoice)?.Id,
            (_selects["admin_it_pic"].SelectedItem as RegistrationChoice)?.Id,
            year, NullIfBlank(_vendor.Text), price);
        DialogResult = true;
        Close();
    }

    private static string? NullIfBlank(string value) => string.IsNullOrWhiteSpace(value) ? null : value.Trim();
}
