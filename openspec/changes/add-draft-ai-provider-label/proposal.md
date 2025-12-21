# Change: Add AI provider label to generated draft titles

## Why
Editors need to see which AI provider generated a draft so they can compare output quality and trace issues.

## What Changes
- Add an AI provider label to the WordPress draft title created from a topic.
- Label should reflect the active provider (e.g., Gemini vs OpenRouter).

## Impact
- Affected specs: draft-generation
- Affected code: `wpagent/includes/class-wpagent-ai.php`
