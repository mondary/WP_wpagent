# Change: Add auto-generation toggles for new topics

## Why
Auto-generating drafts and images for new topics removes manual steps and speeds up the workflow.

## What Changes
- Add configuration toggles for auto-generating drafts and images.
- Provide separate toggles for applying auto-generation to all new topics vs capture-only.
- Trigger auto-generation on topic creation based on the selected toggles.

## Impact
- Affected specs: auto-generation
- Affected code: wpagent/includes/class-wpagent-admin.php, wpagent/includes/class-wpagent-settings.php, wpagent/includes/class-wpagent-ai.php, wpagent/includes/class-wpagent-rest.php
