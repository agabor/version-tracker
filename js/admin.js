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