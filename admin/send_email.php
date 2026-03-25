<?php
require_once '../config.php';
require_once '../db.php';


// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


$applicationId = isset($_GET['application_id']) ? (int)$_GET['application_id'] : 0;
$application = null;
$templates = [];

try {
    // Get email templates
    $stmt = $db->prepare("SELECT * FROM email_templates WHERE is_active = 1 ORDER BY template_name");
    $stmt->execute();
    $templates = $stmt->fetchAll();
    
    // Get application details if application_id is provided
    if ($applicationId) {
        $stmt = $db->prepare("SELECT * FROM investors WHERE id = ?");
        $stmt->execute([$applicationId]);
        $application = $stmt->fetch();
    }
    
    // Get all applications for dropdown
    $stmt = $db->prepare("
        SELECT id, full_name, email, status 
        FROM investors 
        WHERE email IS NOT NULL AND email != ''
        ORDER BY full_name
    ");
    $stmt->execute();
    $allApplications = $stmt->fetchAll();
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

$error = '';
$success = '';

// Handle email sending
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $toApplicationId = isset($_POST['application_id']) ? (int)$_POST['application_id'] : $applicationId;
    $templateId = isset($_POST['template_id']) ? (int)$_POST['template_id'] : 0;
    $customSubject = sanitizeInput($_POST['subject'] ?? '');
    $customBody = $_POST['body'] ?? '';
    $sendCopy = isset($_POST['send_copy']);
    
    if (!$toApplicationId) {
        $error = 'Please select an application.';
    } elseif (!$templateId && (empty($customSubject) || empty($customBody))) {
        $error = 'Please either select a template or provide both subject and body.';
    } else {
        try {
            // Get application details
            $stmt = $db->prepare("SELECT * FROM investors WHERE id = ?");
            $stmt->execute([$toApplicationId]);
            $targetApplication = $stmt->fetch();
            
            if (!$targetApplication) {
                $error = 'Application not found.';
            } else {
                $toEmail = $targetApplication['email'];
                $toName = $targetApplication['full_name'];
                $applicationRef = 'EWF-' . str_pad($targetApplication['id'], 6, '0', STR_PAD_LEFT);
                
                // Prepare email content
                if ($templateId) {
                    // Use template
                    $stmt = $db->prepare("SELECT * FROM email_templates WHERE id = ?");
                    $stmt->execute([$templateId]);
                    $template = $stmt->fetch();
                    
                    $subject = $template['subject'];
                    $body = $template['body'];
                    
                    // Replace template variables
                    $variables = [
                        '{full_name}' => $targetApplication['full_name'],
                        '{application_id}' => $applicationRef,
                        '{nic_no}' => $targetApplication['nic_no'],
                        '{email}' => $targetApplication['email'],
                        '{phone}' => $targetApplication['tel_no'],
                        '{additional_notes}' => sanitizeInput($_POST['additional_notes'] ?? ''),
                        '{rejection_reason}' => sanitizeInput($_POST['rejection_reason'] ?? '')
                    ];
                    
                    $subject = str_replace(array_keys($variables), array_values($variables), $subject);
                    $body = str_replace(array_keys($variables), array_values($variables), $body);
                } else {
                    // Use custom content
                    $subject = $customSubject;
                    $body = $customBody;
                }
                
                // Add signature
                $body .= "\n\n<hr>\n<p style='color: #666; font-size: 12px;'>";
                $body .= "EcoWealth Finance<br>";
                $body .= "Email: support@ecowealth.com<br>";
                $body .= "Phone: +94 11 234 5678<br>";
                $body .= "This is an automated message. Please do not reply to this email.</p>";
                
                // Prepare email headers
                $headers = "MIME-Version: 1.0\r\n";
                $headers .= "Content-type:text/html;charset=UTF-8\r\n";
                $headers .= "From: " . ADMIN_EMAIL . "\r\n";
                $headers .= "Reply-To: " . ADMIN_EMAIL . "\r\n";
                $headers .= "X-Mailer: PHP/" . phpversion();
                $headers .= "\r\nX-Application-ID: " . $applicationRef;
                
                // Send email
                if (mail($toEmail, $subject, $body, $headers)) {
                    // Log sent email
                    $stmt = $db->prepare("
                        INSERT INTO sent_emails (investor_id, admin_id, template_id, subject, body, status)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $toApplicationId,
                        $_SESSION['admin_id'],
                        $templateId ?: null,
                        $subject,
                        $body,
                        'sent'
                    ]);
                    
                    // Log audit
                    $stmt = $db->prepare("
                        INSERT INTO admin_audit_logs (admin_id, action, details, ip_address, user_agent)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $_SESSION['admin_id'],
                        'send_email',
                        "Sent email to {$toName} ({$toEmail}) - Subject: {$subject}",
                        $_SERVER['REMOTE_ADDR'],
                        $_SERVER['HTTP_USER_AGENT']
                    ]);
                    
                    // Send copy to admin if requested
                    if ($sendCopy) {
                        mail(ADMIN_EMAIL, "[COPY] " . $subject, $body, $headers);
                    }
                    
                    $success = "Email sent successfully to {$toName} ({$toEmail})";
                    
                    // Clear form if successful
                    if (!isset($_POST['send_another'])) {
                        $_POST = [];
                    }
                } else {
                    $error = "Failed to send email. Please check your server configuration.";
                    
                    // Log failed attempt
                    $stmt = $db->prepare("
                        INSERT INTO sent_emails (investor_id, admin_id, template_id, subject, body, status)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $toApplicationId,
                        $_SESSION['admin_id'],
                        $templateId ?: null,
                        $subject,
                        $body,
                        'failed'
                    ]);
                }
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
            debug_log('Email sending error', $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Email | EcoWealth Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/admin-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/summernote/0.8.20/summernote-lite.min.css">
    <style>
        .email-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }
        
        @media (max-width: 1200px) {
            .email-container {
                grid-template-columns: 1fr;
            }
        }
        
        .recipient-section {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 12px;
            padding: 25px;
        }
        
        .compose-section {
            background: rgba(10, 47, 29, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 12px;
            padding: 25px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text);
        }
        
        .form-group select,
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            background: rgba(26, 77, 51, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 8px;
            color: var(--text);
            font-size: 14px;
        }
        
        .form-group textarea {
            min-height: 150px;
            resize: vertical;
        }
        
        .template-selector {
            background: rgba(26, 77, 51, 0.5);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .template-option {
            padding: 10px;
            margin-bottom: 10px;
            background: rgba(34, 197, 94, 0.1);
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .template-option:hover {
            background: rgba(34, 197, 94, 0.2);
            border-color: var(--accent);
        }
        
        .template-option.active {
            background: rgba(34, 197, 94, 0.3);
            border: 2px solid var(--accent);
        }
        
        .template-name {
            font-weight: 700;
            color: var(--accent);
            margin-bottom: 5px;
        }
        
        .template-subject {
            font-size: 12px;
            color: var(--text-muted);
        }
        
        .recipient-info {
            background: rgba(26, 77, 51, 0.5);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .recipient-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .detail-item {
            background: rgba(10, 47, 29, 0.7);
            padding: 10px;
            border-radius: 6px;
        }
        
        .detail-label {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 5px;
        }
        
        .detail-value {
            font-weight: 600;
            color: var(--text);
        }
        
        .email-preview {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
            color: #333;
            min-height: 200px;
            overflow-y: auto;
            max-height: 400px;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 20px 0;
        }
        
        .checkbox-group input {
            width: auto;
        }
        
        .btn-group {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }
        
        .btn-full {
            width: 100%;
        }
        
        .tab-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .tab-button {
            padding: 10px 20px;
            background: rgba(26, 77, 51, 0.7);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 8px;
            color: var(--text);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .tab-button.active {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-leaf"></i>Serendib Green Plantaions Admin</h2>
                 <p>Welcome, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?></p>
            </div>
            
            <nav class="sidebar-nav">
                <ul>
                    <li>
                        <a href="dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li>
                        <a href="applications.php">
                            <i class="fas fa-file-alt"></i> Applications
                        </a>
                    </li>
                    <li class="active">
                        <a href="send_email.php">
                            <i class="fas fa-envelope"></i> Send Email
                        </a>
                    </li>
                    <!-- Other menu items -->
                </ul>
            </nav>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="content-header">
                <h1>Send Email to Client</h1>
                <div class="header-actions">
                    <a href="javascript:void(0)" onclick="history.back()" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
            <?php if ($error): ?>
                <div class="alert error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <form method="POST" action="" id="emailForm">
                <div class="email-container">
                    <!-- Left Column: Recipient Selection -->
                    <div class="recipient-section">
                        <div class="section-title">
                            <i class="fas fa-user"></i> Select Recipient
                        </div>
                        
                        <div class="form-group">
                            <label for="application_id">Select Application</label>
                            <select id="application_id" name="application_id" required 
                                    onchange="loadApplicationDetails(this.value)">
                                <option value="">-- Select an application --</option>
                                <?php foreach ($allApplications as $app): ?>
                                    <option value="<?php echo $app['id']; ?>"
                                            <?php echo ($applicationId == $app['id']) ? 'selected' : ''; ?>>
                                        EWF-<?php echo str_pad($app['id'], 6, '0', STR_PAD_LEFT); ?> - 
                                        <?php echo htmlspecialchars($app['full_name']); ?> - 
                                        <?php echo htmlspecialchars($app['email']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <?php if ($application): ?>
                        <div class="recipient-info">
                            <h4>Recipient Information</h4>
                            <div class="recipient-details">
                                <div class="detail-item">
                                    <div class="detail-label">Full Name</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($application['full_name']); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Email</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($application['email']); ?></div>
                                </div>


                                
                                <div class="detail-item">
                                    <div class="detail-label">Phone</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($application['tel_no']); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Application ID</div>
                                    <div class="detail-value">EWF-<?php echo str_pad($application['id'], 6, '0', STR_PAD_LEFT); ?></div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Right Column: Email Composition -->
                    <div class="compose-section">
                        <div class="section-title">
                            <i class="fas fa-edit"></i> Compose Email
                        </div>
                        
                        <div class="tab-buttons">
                            <button type="button" class="tab-button active" onclick="showTab('template')">
                                Use Template
                            </button>
                            <button type="button" class="tab-button" onclick="showTab('custom')">
                                Custom Email
                            </button>
                        </div>
                        
                        <!-- Template Tab -->
                        <div id="templateTab" class="tab-content">
                            <div class="template-selector">
                                <label>Select Email Template:</label>
                                <div id="templateList">
                                    <?php foreach ($templates as $template): ?>
                                        <div class="template-option" 
                                             data-id="<?php echo $template['id']; ?>"
                                             data-subject="<?php echo htmlspecialchars($template['subject']); ?>"
                                             data-body="<?php echo htmlspecialchars($template['body']); ?>"
                                             onclick="selectTemplate(this)">
                                            <div class="template-name"><?php echo htmlspecialchars($template['template_name']); ?></div>
                                            <div class="template-subject"><?php echo htmlspecialchars($template['subject']); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <input type="hidden" id="selected_template_id" name="template_id" value="">
                            
                            <!-- Additional fields for templates -->
                            <div id="templateFields" style="display: none;">
                                <div class="form-group">
                                    <label for="additional_notes">Additional Notes (for "Additional Information Required" template)</label>
                                    <textarea id="additional_notes" name="additional_notes" 
                                              placeholder="Enter the additional information needed..."></textarea>
                                </div>
                                
                                <div class="form-group">
                                    <label for="rejection_reason">Rejection Reason (for "Application Rejected" template)</label>
                                    <textarea id="rejection_reason" name="rejection_reason" 
                                              placeholder="Enter the reason for rejection..."></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Custom Tab -->
                        <div id="customTab" class="tab-content" style="display: none;">
                            <div class="form-group">
                                <label for="subject">Subject</label>
                                <input type="text" id="subject" name="subject" 
                                       placeholder="Enter email subject...">
                            </div>
                            
                            <div class="form-group">
                                <label for="body">Email Body</label>
                                <textarea id="body" name="body" class="summernote"></textarea>
                            </div>
                        </div>
                        
                        <!-- Preview Section -->
                        <div class="form-group">
                            <label>Email Preview</label>
                            <div class="email-preview" id="emailPreview">
                                Select a template or compose an email to see preview...
                            </div>
                        </div>
                        
                        <!-- Options -->
                        <div class="checkbox-group">
                            <input type="checkbox" id="send_copy" name="send_copy" value="1">
                            <label for="send_copy">Send a copy to admin email</label>
                        </div>
                        
                        <div class="checkbox-group">
                            <input type="checkbox" id="send_another" name="send_another" value="1">
                            <label for="send_another">Send another email after this</label>
                        </div>
                        
                        <!-- Submit Buttons -->
                        <div class="btn-group">
                            <button type="submit" class="btn btn-primary btn-full">
                                <i class="fas fa-paper-plane"></i> Send Email
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="previewEmail()">
                                <i class="fas fa-eye"></i> Preview
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Include Summernote WYSIWYG editor -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/summernote/0.8.20/summernote-lite.min.js"></script>
    
    <script>
        let selectedTemplateId = 0;
        
        // Initialize Summernote editor
        $(document).ready(function() {
            $('.summernote').summernote({
                height: 200,
                toolbar: [
                    ['style', ['bold', 'italic', 'underline', 'clear']],
                    ['font', ['strikethrough', 'superscript', 'subscript']],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['insert', ['link', 'picture', 'video']],
                    ['view', ['fullscreen', 'codeview', 'help']]
                ]
            });
        });
        
        function showTab(tabName) {
            // Hide all tabs
            document.getElementById('templateTab').style.display = 'none';
            document.getElementById('customTab').style.display = 'none';
            
            // Remove active class from all buttons
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + 'Tab').style.display = 'block';
            
            // Add active class to clicked button
            event.target.classList.add('active');
            
            // Clear template selection if switching to custom
            if (tabName === 'custom') {
                selectedTemplateId = 0;
                document.getElementById('selected_template_id').value = '';
                document.querySelectorAll('.template-option').forEach(opt => {
                    opt.classList.remove('active');
                });
            }
        }
        
        function selectTemplate(element) {
            // Remove active class from all templates
            document.querySelectorAll('.template-option').forEach(opt => {
                opt.classList.remove('active');
            });
            
            // Add active class to selected template
            element.classList.add('active');
            
            // Set template ID
            selectedTemplateId = element.dataset.id;
            document.getElementById('selected_template_id').value = selectedTemplateId;
            
            // Update preview
            updatePreview(element.dataset.subject, element.dataset.body);
            
            // Show/hide additional fields based on template
            const templateName = element.querySelector('.template-name').textContent;
            const templateFields = document.getElementById('templateFields');
            
            if (templateName.includes('Additional Information Required')) {
                templateFields.style.display = 'block';
                document.getElementById('additional_notes').required = true;
                document.getElementById('rejection_reason').required = false;
            } else if (templateName.includes('Application Rejected')) {
                templateFields.style.display = 'block';
                document.getElementById('additional_notes').required = false;
                document.getElementById('rejection_reason').required = true;
            } else {
                templateFields.style.display = 'none';
                document.getElementById('additional_notes').required = false;
                document.getElementById('rejection_reason').required = false;
            }
        }
        
        function loadApplicationDetails(appId) {
            if (appId) {
                window.location.href = `send_email.php?application_id=${appId}`;
            }
        }
        
        function updatePreview(subject, body) {
            const preview = document.getElementById('emailPreview');
            
            // Replace template variables with sample data
            let previewBody = body
                .replace(/{full_name}/g, 'John Doe')
                .replace(/{application_id}/g, 'EWF-000001')
                .replace(/{nic_no}/g, '123456789V')
                .replace(/{email}/g, 'john@example.com')
                .replace(/{phone}/g, '+94 77 123 4567')
                .replace(/{additional_notes}/g, 'Please provide your recent bank statement.')
                .replace(/{rejection_reason}/g, 'Incomplete documentation provided.');
            
            preview.innerHTML = `
                <div style="margin-bottom: 20px;">
                    <strong>Subject:</strong> ${subject}
                </div>
                <hr>
                <div>${previewBody}</div>
            `;
        }
        
        function previewEmail() {
            let subject, body;
            
            if (selectedTemplateId) {
                const selectedTemplate = document.querySelector('.template-option.active');
                subject = selectedTemplate.dataset.subject;
                body = selectedTemplate.dataset.body;
            } else {
                subject = document.getElementById('subject').value;
                body = $('.summernote').summernote('code');
            }
            
            if (!subject || !body) {
                alert('Please provide both subject and body for the email.');
                return;
            }
            
            // Open preview in new window
            const previewWindow = window.open('', '_blank');
            previewWindow.document.write(`
                <html>
                <head>
                    <title>Email Preview</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        .preview-container { max-width: 800px; margin: 0 auto; }
                        .subject { font-size: 18px; font-weight: bold; margin-bottom: 20px; }
                        .body { line-height: 1.6; }
                    </style>
                </head>
                <body>
                    <div class="preview-container">
                        <div class="subject">Subject: ${subject}</div>
                        <div class="body">${body}</div>
                        <hr>
                        <div style="color: #666; font-size: 12px; margin-top: 30px;">
                            <p>This is a preview of the email that will be sent.</p>
                            <p>Footer will be automatically added with company details.</p>
                        </div>
                    </div>
                </body>
                </html>
            `);
            previewWindow.document.close();
        }
        
        // Auto-update preview when typing in custom tab
        document.getElementById('subject')?.addEventListener('input', function() {
            if (!selectedTemplateId) {
                updateCustomPreview();
            }
        });
        
        function updateCustomPreview() {
            const subject = document.getElementById('subject').value;
            const body = $('.summernote').summernote('code');
            
            if (subject || body) {
                const preview = document.getElementById('emailPreview');
                preview.innerHTML = `
                    <div style="margin-bottom: 20px;">
                        <strong>Subject:</strong> ${subject || '(No subject)'}
                    </div>
                    <hr>
                    <div>${body || '(No content)'}</div>
                `;
            }
        }
        
        // Initialize with first template selected if available
        document.addEventListener('DOMContentLoaded', function() {
            const firstTemplate = document.querySelector('.template-option');
            if (firstTemplate) {
                selectTemplate(firstTemplate);
            }
        });
    </script>
</body>
</html>