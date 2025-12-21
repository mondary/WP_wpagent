## ADDED Requirements
### Requirement: Draft title indicates AI provider
When the system generates a WordPress draft from a topic, it SHALL include a visible provider label in the draft title.

#### Scenario: Draft generated with Gemini
- **WHEN** the configured provider is Gemini and a draft is generated
- **THEN** the draft title includes a Gemini label

#### Scenario: Draft generated with OpenRouter
- **WHEN** the configured provider is OpenRouter and a draft is generated
- **THEN** the draft title includes an OpenRouter label
