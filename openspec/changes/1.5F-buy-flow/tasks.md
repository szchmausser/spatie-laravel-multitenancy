# Tasks: 1.5F — Buy flow (simulated purchase)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~400 |
| 400-line budget risk | Low (chained PR not required) |
| Chained PRs recommended | No |
| Suggested split | Single PR; the controller change, the new dialog, and the page wiring are all part of one cohesive UX change |

## Task list

- [x] T1. Update existing tests in `tests/Feature/Resource/ResourceControllerTest.php` to reflect the new behavior (6 free-tier tests + 1 starter-plan test). Add 1 new test for the end-to-end buy flow.
- [x] T2. In `app/Http/Controllers/Resource/ResourceController.php`: remove `where('is_premium', false)` from `index`/`show`/`download`; remove the `canSeePremium` gate in `request`; delete the `canSeePremium()` helper; update the docblock.
- [x] T3. Create `resources/js/components/resources/buy-resource-dialog.tsx` (shadcn Dialog, `useForm`, Wayfinder `request` route, Phase 2 marker in `handleSubmit`).
- [x] T4. In `resources/js/pages/resources/index.tsx` and `resources/js/pages/resources/show.tsx`: replace the inline `<Form>` "Request Access" with a "Buy" `<Button>` + dialog.
- [x] T5. Full test suite green (188/185 passing, 3 skipped, 0 regressions).
- [x] T6. `npm run types:check` and `npm run lint` clean on changed files.
- [x] T7. `vendor/bin/pint --dirty` clean.
- [x] T8. Document the manual browser smoke test in the final report.
- [x] T9. Update `Arquitectura multitenencia aplicada.md` §22.1 and add §22.1.1.
