# Changelog

Notable changes to `yacoubalhaidari/provider-payments-sa` are recorded here.
Unreleased changes have no published version or release date. Existing capabilities
listed under Known limitations describe the current package; they are not newly
introduced features.

For setup and examples, start with the [README](README.md) or the
[integration reference](docs/integration-guide.md).

## [Unreleased]

### Changed

- Reorganized the README around installation, gateway selection, checkout creation
  and payment confirmation.
- Added direct navigation and expandable configuration sections for ClickPay,
  Telr, Tabby, Tamara, MisPay, AlinmaPay and AlRajhi.
- Added the Provider Payments SA banner to the README.
- Moved detailed application examples and operational guidance into the linked
  integration reference, preserving the complete order-to-checkout example.
- Expanded `.gitignore` to exclude local credentials, dependencies, caches,
  coverage reports, runtime data, scratch files and editor/assistant settings.
  Source code, test fixtures and environment examples remain available.
- Expanded `.gitattributes` exclusions for development files in Git release
  archives while retaining user-facing documentation.

### Compatibility and upgrade notes

- These changes affect documentation and repository packaging rules; they do not
  change the payment API, database schema or gateway behavior.
- The package requires PHP `^8.3` and Laravel 12 or 13. See
  [installation requirements](docs/integration-guide.md#3-requirements).
- `.gitignore` does not remove files already tracked by Git. Review tracked files
  before publishing; ignoring a credential does not revoke it.
- `.gitattributes` export exclusions apply to Git-generated release archives.
  They do not remove development files from a source checkout.

### Known limitations

#### Gateway operations

- Supported operations differ by gateway. Check the
  [capability matrix](docs/integration-guide.md#2-supported-providers) and
  `supports()` before invoking an optional operation.
- MisPay has no inquiry or refund API in this integration. Confirmation relies
  on authenticated notifications and reconciliation with the merchant provider.
- AlRajhi supports hosted checkout and inquiry; refund, capture and cancellation
  are not exposed by this adapter.
- Tabby and Tamara capture the full amount only. AlinmaPay captures the full
  authorized amount. Merchant permissions and payment state still apply.

#### Tamara eligibility

- `checkEligibility()` provides the pre-checkout customer eligibility check.
  The consuming application must call it when rendering/loading checkout and use
  the result to disable or hide Tamara; the package does not automatically bind
  this behavior to a checkout page or Livewire component.
- An explicit ineligible response returns `false`. Network/request failures or
  an unrecognized eligibility response return `true` to keep the option visible.
  This fallback is a display policy, not approval of financing or proof of payment.
- Default settings use a 200 ms request timeout, a 60-second result cache and a
  10-second failure cooldown. There is no fixed three- or five-second UI delay.

#### Payment confirmation and recovery

- Browser redirects, checkout creation and eligibility checks do not establish
  payment success. Update orders from authenticated, verified payment processing.
- Authorization is distinct from paid/captured status. Fulfillment must respect
  the verified financial state required by the merchant flow.
- Keep the SQL ledger enabled for webhook handling and durable operation tracking.
  The ledger does not replace application orders, inventory or fulfillment logic.
- Unknown checkout, capture or refund outcomes require reconciliation. Financial
  POST requests are not automatically retried; do not change operation references
  or delete reservations merely to resend them.
- Promotional widgets display payment/installment information; they do not
  collect card details, create checkout sessions or confirm payment.

#### Verification scope

- Automated HTTP-fake tests do not establish provider certification or merchant
  sandbox acceptance. Verify live account settings, public HTTPS callbacks,
  hosted widgets and concurrency against the deployment's SQL database.
- Eligibility tests were checked locally: 4 tests and 8 assertions passed. This
  focused check does not represent a full-suite run or a live Tamara API test.

See [operational guidance](docs/integration-guide.md#15-operational-limits-and-license)
for deployment responsibilities and third-party terms.
