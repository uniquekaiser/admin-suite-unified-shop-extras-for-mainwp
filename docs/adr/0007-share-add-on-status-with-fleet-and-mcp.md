# Share add-on status with Fleet and MCP

The dashboard add-on owns managed-site status for both products. Fleet displays cached status and opens the corresponding add-on workflow, and the MainWP MCP Bridge exposes read-only status within the operator's site access during the pilot. A shared source keeps edition, activation and entitlement reporting consistent across the three surfaces.

Refresh status after successful operations, during normal MainWP sync and on explicit request. If the child is unreachable, expose the connection state and the time of its last verified status.
