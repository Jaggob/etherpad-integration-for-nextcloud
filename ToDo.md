# ToDo

Status date: 2026-03-23

## Open Items

1. **Legacy import tool**
   - dry-run report
   - conflict classes
   - targeted write import

2. **Template feature**
   - mark pads as templates
   - create new pads from selected template
   - prefill editable target pad name
   - YAML template tags for name generation (prefix/date/placeholders)
   - preload initial content into newly created pads

3. **Pad entry in Files type filter**
   - only if a stable official Nextcloud extension point exists
   - avoid brittle DOM patching

4. **Sidebar pad actions (rename/move)**
   - explicit `Rename pad` / `Move pad` actions in sidebar
   - decide if/when Etherpad `movePad` should be used for internal pads
   - keep binding consistency strict

5. **Disallow `.pad` copy more explicitly**
   - preferred technical path: block copy server-side via `BeforeNodeCopiedEvent`
   - this is more robust than trying to hide `Copy` only in the Files UI
   - expected follow-up: evaluate whether a clearer user-facing error/notification can be shown when copy is rejected
   - avoid brittle DOM patching of the three-dots menu unless no stable alternative exists

6. **error messages**
   - better messages e.g. for health check errors

## Recently Completed (Summary)

- Release check runs green (`PHPUnit` + core E2E on test server).
- NC31/NC32 sidebar icon bleed fixed via dedicated sidebar sync mount node.
- Public-share route expectations aligned (`303|400` where applicable).
- Pad-copy E2E hardened so SSRF-rejected `from-url` does not abort later checks.

For historical details, see [CHANGELOG.md](CHANGELOG.md).
