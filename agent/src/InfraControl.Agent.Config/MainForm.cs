using System.Drawing;
using System.Windows.Forms;
using InfraControl.Agent.Core.Constants;
using InfraControl.Agent.Core.Infrastructure;
using InfraControl.Agent.Core.Models;
using InfraControl.Agent.Core.Services;

namespace InfraControl.Agent.Config;

public sealed class MainForm : Form
{
    private readonly ISecretProtector _secretProtector = new DpapiSecretProtector();
    private readonly IAgentConfigurationStore _configurationStore;
    private readonly IAgentStatusStore _statusStore;
    private readonly WindowsServiceManager _serviceManager;
    private readonly System.Windows.Forms.Timer _refreshTimer;
    private readonly TextBox _serverUrlTextBox;
    private readonly TextBox _enrollmentTokenTextBox;
    private readonly Label _serviceStatusValueLabel;
    private readonly Label _agentStatusValueLabel;
    private readonly Label _lastUpdatedValueLabel;
    private readonly Label _messageValueLabel;
    private readonly Label _feedbackLabel;
    private readonly Button _saveButton;
    private readonly Button _startButton;
    private readonly Button _stopButton;

    public MainForm()
    {
        Text = "InfraControl Agent Config";
        MinimumSize = new Size(720, 520);
        Size = new Size(760, 560);
        Font = new Font("Segoe UI", 10F, FontStyle.Regular, GraphicsUnit.Point);
        StartPosition = FormStartPosition.CenterScreen;

        _configurationStore = new FileAgentConfigurationStore(AgentPaths.ConfigPath, _secretProtector);
        _statusStore = new FileAgentStatusStore(AgentPaths.StatusPath);
        _serviceManager = new WindowsServiceManager(AgentConstants.ServiceName);

        Panel root = new()
        {
            Dock = DockStyle.Fill,
            Padding = new Padding(24),
            BackColor = Color.FromArgb(248, 249, 252),
        };
        Controls.Add(root);

        Label title = new()
        {
            Text = "InfraControl Agent Config",
            Dock = DockStyle.Top,
            Height = 40,
            Font = new Font(Font, FontStyle.Bold),
        };
        root.Controls.Add(title);

        Label subtitle = new()
        {
            Text = "Set the server URL and enrollment token, then control the Windows service from one place.",
            Dock = DockStyle.Top,
            Height = 36,
            ForeColor = Color.FromArgb(86, 94, 115),
        };
        root.Controls.Add(subtitle);

        TableLayoutPanel layout = new()
        {
            Dock = DockStyle.Fill,
            ColumnCount = 1,
            RowCount = 4,
            Padding = new Padding(0, 12, 0, 0),
        };
        layout.RowStyles.Add(new RowStyle(SizeType.Absolute, 170));
        layout.RowStyles.Add(new RowStyle(SizeType.Absolute, 64));
        layout.RowStyles.Add(new RowStyle(SizeType.Absolute, 72));
        layout.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        root.Controls.Add(layout);

        Panel configPanel = BuildCardPanel();
        layout.Controls.Add(configPanel, 0, 0);

        _serverUrlTextBox = BuildTextBox();
        _enrollmentTokenTextBox = BuildTextBox();
        _enrollmentTokenTextBox.UseSystemPasswordChar = true;

        configPanel.Controls.Add(BuildFieldLabel("Server URL", 18));
        _serverUrlTextBox.Location = new Point(18, 46);
        _serverUrlTextBox.Width = 650;
        configPanel.Controls.Add(_serverUrlTextBox);

        configPanel.Controls.Add(BuildFieldLabel("Enrollment Token", 92));
        _enrollmentTokenTextBox.Location = new Point(18, 120);
        _enrollmentTokenTextBox.Width = 650;
        configPanel.Controls.Add(_enrollmentTokenTextBox);

        Panel buttonPanel = BuildCardPanel();
        layout.Controls.Add(buttonPanel, 0, 1);

        _saveButton = BuildButton("Save", Color.FromArgb(23, 92, 211));
        _saveButton.Location = new Point(18, 14);
        _saveButton.Click += async (_, _) => await SaveConfigurationAsync();
        buttonPanel.Controls.Add(_saveButton);

        _startButton = BuildButton("Start Service", Color.FromArgb(17, 113, 75));
        _startButton.Location = new Point(148, 14);
        _startButton.Click += async (_, _) => await StartServiceAsync();
        buttonPanel.Controls.Add(_startButton);

        _stopButton = BuildButton("Stop Service", Color.FromArgb(160, 35, 35));
        _stopButton.Location = new Point(308, 14);
        _stopButton.Click += async (_, _) => await StopServiceAsync();
        buttonPanel.Controls.Add(_stopButton);

        _feedbackLabel = new Label
        {
            AutoSize = false,
            Location = new Point(450, 18),
            Size = new Size(220, 28),
            TextAlign = ContentAlignment.MiddleRight,
            ForeColor = Color.FromArgb(60, 68, 89),
        };
        buttonPanel.Controls.Add(_feedbackLabel);

        Panel statusSummaryPanel = BuildCardPanel();
        layout.Controls.Add(statusSummaryPanel, 0, 2);

        _serviceStatusValueLabel = BuildStatusValueLabel(18);
        _agentStatusValueLabel = BuildStatusValueLabel(230);
        _lastUpdatedValueLabel = BuildStatusValueLabel(460);

        statusSummaryPanel.Controls.Add(BuildSummaryBlock("Service", _serviceStatusValueLabel));
        statusSummaryPanel.Controls.Add(BuildSummaryBlock("Agent Status", _agentStatusValueLabel, 212));
        statusSummaryPanel.Controls.Add(BuildSummaryBlock("Last Update", _lastUpdatedValueLabel, 442));

        Panel detailPanel = BuildCardPanel();
        layout.Controls.Add(detailPanel, 0, 3);

        Label detailHeader = new()
        {
            Text = "Status Detail",
            Location = new Point(18, 18),
            Size = new Size(200, 24),
            Font = new Font(Font, FontStyle.Bold),
        };
        detailPanel.Controls.Add(detailHeader);

        _messageValueLabel = new Label
        {
            Location = new Point(18, 54),
            Size = new Size(650, 120),
            AutoEllipsis = true,
            ForeColor = Color.FromArgb(60, 68, 89),
        };
        detailPanel.Controls.Add(_messageValueLabel);

        Label noteLabel = new()
        {
            Location = new Point(18, 186),
            Size = new Size(650, 58),
            ForeColor = Color.FromArgb(86, 94, 115),
            Text = "Expected v1 statuses: not configured, enrolled, invalid token, server unreachable, and heartbeat healthy. Start the service after saving the configuration.",
        };
        detailPanel.Controls.Add(noteLabel);

        Load += async (_, _) =>
        {
            await LoadConfigurationAsync();
            await RefreshRuntimeStatusAsync();
        };

        _refreshTimer = new System.Windows.Forms.Timer
        {
            Interval = 5000,
            Enabled = true,
        };
        _refreshTimer.Tick += async (_, _) => await RefreshRuntimeStatusAsync();
    }

    private async Task LoadConfigurationAsync()
    {
        AgentConfiguration configuration = await _configurationStore.LoadAsync();
        _serverUrlTextBox.Text = configuration.ServerUrl ?? string.Empty;
        _enrollmentTokenTextBox.Text = configuration.EnrollmentToken ?? string.Empty;
    }

    private async Task SaveConfigurationAsync()
    {
        try
        {
            AgentConfiguration current = await _configurationStore.LoadAsync();
            current.ServerUrl = Normalize(_serverUrlTextBox.Text);
            current.EnrollmentToken = Normalize(_enrollmentTokenTextBox.Text);

            await _configurationStore.SaveAsync(current);
            _feedbackLabel.ForeColor = Color.FromArgb(17, 113, 75);
            _feedbackLabel.Text = "Configuration saved";
        }
        catch (Exception exception)
        {
            _feedbackLabel.ForeColor = Color.FromArgb(160, 35, 35);
            _feedbackLabel.Text = exception.Message;
        }
    }

    private async Task StartServiceAsync()
    {
        try
        {
            await _serviceManager.StartAsync();
            _feedbackLabel.ForeColor = Color.FromArgb(17, 113, 75);
            _feedbackLabel.Text = "Service started";
        }
        catch (Exception exception)
        {
            _feedbackLabel.ForeColor = Color.FromArgb(160, 35, 35);
            _feedbackLabel.Text = exception.Message;
        }

        await RefreshRuntimeStatusAsync();
    }

    private async Task StopServiceAsync()
    {
        try
        {
            await _serviceManager.StopAsync();
            _feedbackLabel.ForeColor = Color.FromArgb(17, 113, 75);
            _feedbackLabel.Text = "Service stopped";
        }
        catch (Exception exception)
        {
            _feedbackLabel.ForeColor = Color.FromArgb(160, 35, 35);
            _feedbackLabel.Text = exception.Message;
        }

        await RefreshRuntimeStatusAsync();
    }

    private async Task RefreshRuntimeStatusAsync()
    {
        AgentStatusSnapshot status = await _statusStore.LoadAsync();
        ServiceControlSnapshot service = _serviceManager.GetSnapshot();

        _serviceStatusValueLabel.Text = service.DisplayText;
        _agentStatusValueLabel.Text = MapStatusCode(status.StatusCode);
        _lastUpdatedValueLabel.Text = status.UpdatedAt.LocalDateTime.ToString("yyyy-MM-dd HH:mm:ss");
        _messageValueLabel.Text = string.IsNullOrWhiteSpace(status.Message) ? "-" : status.Message;
        _startButton.Enabled = service.Installed && service.State != AgentServiceState.Running && service.State != AgentServiceState.StartPending;
        _stopButton.Enabled = service.Installed && service.State == AgentServiceState.Running;
    }

    private static Panel BuildCardPanel() => new()
    {
        Dock = DockStyle.Fill,
        BackColor = Color.White,
        BorderStyle = BorderStyle.FixedSingle,
    };

    private static Label BuildFieldLabel(string text, int top) => new()
    {
        Text = text,
        Location = new Point(18, top),
        Size = new Size(220, 22),
        Font = new Font("Segoe UI", 9F, FontStyle.Bold, GraphicsUnit.Point),
    };

    private static TextBox BuildTextBox() => new()
    {
        BorderStyle = BorderStyle.FixedSingle,
        Font = new Font("Segoe UI", 10F, FontStyle.Regular, GraphicsUnit.Point),
    };

    private static Button BuildButton(string text, Color backColor) => new()
    {
        Text = text,
        Size = new Size(132, 36),
        FlatStyle = FlatStyle.Flat,
        BackColor = backColor,
        ForeColor = Color.White,
    };

    private static Panel BuildSummaryBlock(string title, Label valueLabel, int left = 0)
    {
        Panel panel = new()
        {
            Location = new Point(18 + left, 14),
            Size = new Size(190, 42),
            BackColor = Color.White,
        };

        Label titleLabel = new()
        {
            Text = title,
            Size = new Size(180, 18),
            Font = new Font("Segoe UI", 8F, FontStyle.Bold, GraphicsUnit.Point),
            ForeColor = Color.FromArgb(86, 94, 115),
        };
        panel.Controls.Add(titleLabel);

        valueLabel.Location = new Point(0, 20);
        panel.Controls.Add(valueLabel);

        return panel;
    }

    private static Label BuildStatusValueLabel(int left) => new()
    {
        Location = new Point(left, 34),
        Size = new Size(180, 20),
        Font = new Font("Segoe UI", 10F, FontStyle.Regular, GraphicsUnit.Point),
        ForeColor = Color.FromArgb(35, 40, 52),
    };

    private static string MapStatusCode(AgentRuntimeStatusCode statusCode) =>
        statusCode switch
        {
            AgentRuntimeStatusCode.NotConfigured => "Not configured",
            AgentRuntimeStatusCode.Enrolling => "Enrolling",
            AgentRuntimeStatusCode.Enrolled => "Enrolled",
            AgentRuntimeStatusCode.InvalidEnrollmentToken => "Invalid token",
            AgentRuntimeStatusCode.ServerUnreachable => "Server unreachable",
            AgentRuntimeStatusCode.HeartbeatHealthy => "Heartbeat healthy",
            AgentRuntimeStatusCode.HeartbeatDegraded => "Heartbeat degraded",
            AgentRuntimeStatusCode.ServiceStopped => "Service stopped",
            _ => "Error",
        };

    private static string? Normalize(string? value) =>
        string.IsNullOrWhiteSpace(value) ? null : value.Trim();
}
