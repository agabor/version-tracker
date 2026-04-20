jQuery(document).ready(function($) {
    let savedEmails = '';

    function loadSavedEmails() {
        $.ajax({
            url: versionTrackerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'version_tracker_get_saved_email'
            },
            success: function(response) {
                var data = JSON.parse(response);
                if (data.email) {
                    savedEmails = data.email;
                    $('#vt-report-email-input').val(savedEmails);
                }
            }
        });
    }
    
    loadSavedEmails();
    
    $('#vt-send-report-btn').on('click', function() {
        const $btn = $(this);
        const originalText = $btn.text();
        const emailInput = $('#vt-report-email-input').val().trim();

        if (!emailInput) {
            alert('Please enter at least one email address');
            return;
        }

        const validation = validateEmailList(emailInput);
        
        if (!validation.isValid) {
            alert('Invalid email addresses: ' + validation.invalidEmails.join(', '));
            return;
        }
        
        if (validation.validEmails.length === 0) {
            alert('Please enter at least one valid email address');
            return;
        }
        
        $btn.prop('disabled', true).text('Sending...');
        
        $.ajax({
            url: versionTrackerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: versionTrackerAdmin.sendReportAction,
                recipient_emails: emailInput
            },
            success: function(response) {
                const data = JSON.parse(response);
                if (data.success) {
                    alert(data.message);
                    $btn.prop('disabled', false).text(originalText);
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                    $btn.prop('disabled', false).text(originalText);
                }
            },
            error: function() {
                alert('Error sending report');
                $btn.prop('disabled', false).text(originalText);
            }
        });
    });

    $('#vt-mark-all-reported-btn').on('click', function() {
        if (!confirm('Mark all unreported changes as reported?')) {
            return;
        }

        const $btn = $(this);
        const originalText = $btn.text();
        $btn.prop('disabled', true).text('Marking...');

        $.ajax({
            url: versionTrackerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'version_tracker_mark_all_reported'
            },
            success: function(response) {
                const data = JSON.parse(response);
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                    $btn.prop('disabled', false).text(originalText);
                }
            },
            error: function() {
                alert('Error marking records as reported');
                $btn.prop('disabled', false).text(originalText);
            }
        });
    });

    $(document).on('click', '.vt-mark-reported-btn', function() {
        const recordId = $(this).data('record-id');
        markAsReported([recordId]);
    });

    $(document).on('click', '.vt-mark-selected-btn', function() {
        const state = $(this).data('state');
        const selectedIds = [];

        $(`input.vt-record-select[data-state="${state}"]:checked`).each(function() {
            selectedIds.push($(this).val());
        });

        if (selectedIds.length === 0) {
            alert('Please select at least one change to mark as reported');
            return;
        }

        if (!confirm('Mark selected changes as reported?')) {
            return;
        }

        markAsReported(selectedIds);
    });

    $(document).on('change', '.vt-section-select-all', function() {
        const state = $(this).data('state');
        const isChecked = $(this).prop('checked');

        $(`input.vt-record-select[data-state="${state}"]`).prop('checked', isChecked);
    });

    $(document).on('change', 'input.vt-record-select', function() {
        const state = $(this).data('state');
        const totalCheckboxes = $(`input.vt-record-select[data-state="${state}"]`).length;
        const checkedCheckboxes = $(`input.vt-record-select[data-state="${state}"]:checked`).length;

        const sectionCheckAll = $(`.vt-section-select-all[data-state="${state}"]`);
        sectionCheckAll.prop('checked', totalCheckboxes === checkedCheckboxes && totalCheckboxes > 0);
    });

    function markAsReported(recordIds) {
        $.ajax({
            url: versionTrackerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'version_tracker_mark_reported',
                record_ids: recordIds
            },
            success: function(response) {
                const data = JSON.parse(response);
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            },
            error: function() {
                alert('Error marking records as reported');
            }
        });
    }
    
    function validateEmailList(emailString) {
        const emails = emailString.split(',').map(function(email) {
            return email.trim();
        }).filter(function(email) {
            return email.length > 0;
        });
        
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        const validEmails = [];
        const invalidEmails = [];
        
        emails.forEach(function(email) {
            if (emailRegex.test(email)) {
                validEmails.push(email);
            } else {
                invalidEmails.push(email);
            }
        });
        
        return {
            isValid: invalidEmails.length === 0,
            validEmails: validEmails,
            invalidEmails: invalidEmails
        };
    }
});