jQuery(document).ready(function($) {
    let savedEmail = '';

    function loadSavedEmail() {
        $.ajax({
            url: versionTrackerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'version_tracker_get_saved_email'
            },
            success: function(response) {
                var data = JSON.parse(response);
                if (data.email) {
                    savedEmail = data.email;
                    $('#vt-report-email-input').val(savedEmail);
                }
            }
        });
    }
    
    loadSavedEmail();
    
    $('#vt-filter-btn').on('click', function() {
        const selectedCheckpointId = $('#vt-checkpoint-selector').val();

        $.ajax({
            url: versionTrackerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: versionTrackerAdmin.getVersionsAction,
                checkpoint_id: selectedCheckpointId
            },
            success: function(response) {
                var data = JSON.parse(response);
                if (data.error) {
                    alert('Error: ' + data.error);
                } else {
                    $('#vt-versions-container').html(data.html);
                }
            },
            error: function() {
                alert('Error loading versions');
            }
        });
    });
    
    $('#vt-manual-check-btn').on('click', function() {
        const $btn = $(this);
        const originalText = $btn.text();
        $btn.prop('disabled', true).text('Checking...');
        
        $.ajax({
            url: versionTrackerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: versionTrackerAdmin.manualCheckAction
            },
            success: function(response) {
                var data = JSON.parse(response);
                if (data.success) {
                    alert('Version check completed successfully');
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                    $btn.prop('disabled', false).text(originalText);
                }
            },
            error: function() {
                alert('Error performing version check');
                $btn.prop('disabled', false).text(originalText);
            }
        });
    });
    
    $('#vt-create-checkpoint-btn').on('click', function() {
        $.ajax({
            url: versionTrackerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: versionTrackerAdmin.createCheckpointAction
            },
            success: function(response) {
                const data = JSON.parse(response);
                if (data.success) {
                    alert('Checkpoint created successfully');
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            },
            error: function() {
                alert('Error creating checkpoint');
            }
        });
    });
    
    $('#vt-send-report-btn').on('click', function() {
        const $btn = $(this);
        const originalText = $btn.text();
        const selectedCheckpointId = $('#vt-checkpoint-selector').val();
        const emailInput = $('#vt-report-email-input').val().trim();

        if (!emailInput) {
            alert('Please enter an email address');
            return;
        }

        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(emailInput)) {
            alert('Please enter a valid email address');
            return;
        }
        
        $btn.prop('disabled', true).text('Sending...');
        
        $.ajax({
            url: versionTrackerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: versionTrackerAdmin.sendReportAction,
                checkpoint_id: selectedCheckpointId,
                recipient_email: emailInput
            },
            success: function(response) {
                const data = JSON.parse(response);
                if (data.success) {
                    alert(data.message);
                    $btn.prop('disabled', false).text(originalText);
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
    
    $('#vt-delete-checkpoint-btn').on('click', function() {
        if (!confirm('Are you sure you want to delete the last checkpoint?')) {
            return;
        }
        
        $.ajax({
            url: versionTrackerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: versionTrackerAdmin.deleteCheckpointAction
            },
            success: function(response) {
                const data = JSON.parse(response);
                if (data.success) {
                    alert('Checkpoint deleted successfully');
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            },
            error: function() {
                alert('Error deleting checkpoint');
            }
        });
    });
});