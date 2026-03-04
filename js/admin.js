jQuery(document).ready(function($) {
    $('#vt-filter-btn').on('click', function() {
        var selectedCheckpointId = $('#vt-checkpoint-selector').val();
        
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
        var $btn = $(this);
        var originalText = $btn.text();
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
                var data = JSON.parse(response);
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
        var $btn = $(this);
        var originalText = $btn.text();
        var selectedCheckpointId = $('#vt-checkpoint-selector').val();
        $btn.prop('disabled', true).text('Sending...');
        
        $.ajax({
            url: versionTrackerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: versionTrackerAdmin.sendReportAction,
                checkpoint_id: selectedCheckpointId
            },
            success: function(response) {
                var data = JSON.parse(response);
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
                var data = JSON.parse(response);
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