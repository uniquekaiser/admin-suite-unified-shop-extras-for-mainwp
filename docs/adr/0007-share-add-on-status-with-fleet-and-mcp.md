# Share add-on status with Fleet and MCP

The Dashboard add-on owns managed-site status for both products. Fleet displays cached status and opens the corresponding add-on workflow. The MainWP MCP Bridge exposes status within the operator's site access. A shared source keeps edition, activation and entitlement reporting consistent across the three surfaces.

The [MCP abilities](../mainwp-mcp.md) also support single-site onboarding and the two approved pilot settings. Mutations reuse the add-on's site permissions, signed package and license flow, and the product's signed settings contract. Each write requires a site-specific preview followed by confirmation.

Refresh status after successful operations, during normal MainWP sync and on explicit request. If the child is unreachable, expose the connection state and the time of its last verified status.
