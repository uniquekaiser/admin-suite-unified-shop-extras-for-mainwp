# Install from a publisher-approved package catalog

Install operations will resolve a publisher-approved ZIP by product, edition and version, verify the artifact against trusted release metadata, and use a short-lived URL for delivery to the managed site. A catalog gives operators a predictable package and version across a fleet. Access to each package follows the product's distribution rules.

Both products share one versioned catalog signed by the publisher. The add-on verifies the signature with its pinned publisher key before trusting the metadata. Lite entries identify the approved public ZIP and its hash. Pro entries identify the release manifest used to verify the licensed Dashboard installation as defined in [ADR 0005](0005-source-pro-packages-from-the-dashboard.md).
