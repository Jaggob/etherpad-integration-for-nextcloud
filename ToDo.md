# ToDo

Status date: 2026-03-11

## Open Items

### Release-Critical (First Release)

- None currently.

### Important (Non-Blocking)

- None currently.

## Nice-to-Have (Post-Release)

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

## Recently Completed (Summary)

- Release check runs green (`PHPUnit` + core E2E on test server).
- NC31/NC32 sidebar icon bleed fixed via dedicated sidebar sync mount node.
- Public-share route expectations aligned (`303|400` where applicable).
- Pad-copy E2E hardened so SSRF-rejected `from-url` does not abort later checks.

For historical details, see [CHANGELOG.md](CHANGELOG.md).
