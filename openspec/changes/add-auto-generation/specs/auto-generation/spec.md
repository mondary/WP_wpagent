## ADDED Requirements
### Requirement: Auto-generation toggles
The system SHALL provide configuration toggles to control automatic draft generation and image discovery for new topics.

#### Scenario: Admin configures toggles
- **WHEN** an admin updates the auto-generation settings
- **THEN** the system stores the selected toggles for future topic creation

### Requirement: Draft auto-generation scope
The system SHALL support separate scopes for auto-generating drafts on new topics: all sources or capture-only.

#### Scenario: Draft auto-generation for all sources
- **WHEN** the draft auto-generation scope is set to all sources
- **AND** a new topic is created from any source
- **THEN** the system generates a draft for the topic automatically

#### Scenario: Draft auto-generation for capture-only
- **WHEN** the draft auto-generation scope is set to capture-only
- **AND** a new topic is created from a capture endpoint
- **THEN** the system generates a draft for the topic automatically

### Requirement: Image auto-generation scope
The system SHALL support separate scopes for auto-discovering images on new topics: all sources or capture-only.

#### Scenario: Image auto-generation for all sources
- **WHEN** the image auto-generation scope is set to all sources
- **AND** a new topic is created from any source
- **THEN** the system attempts to discover and associate an image automatically

#### Scenario: Image auto-generation for capture-only
- **WHEN** the image auto-generation scope is set to capture-only
- **AND** a new topic is created from a capture endpoint
- **THEN** the system attempts to discover and associate an image automatically

### Requirement: Image discovery without URL
The system SHALL attempt to discover an illustrative image even when a topic does not include a source URL.

#### Scenario: Image discovery without source URL
- **WHEN** a new topic is created without a source URL
- **AND** image auto-generation is enabled for the topic
- **THEN** the system attempts to discover an illustrative image using the topic content
