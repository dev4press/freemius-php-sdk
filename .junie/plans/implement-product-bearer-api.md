---
sessionId: session-260727-214907-cqke
---

# Requirements

### Overview & Goals
Complete the product-scope bearer API in `freemius/Product.php` from the OpenAPI specification, while tracking progress by endpoint block rather than broad grouped steps.

OpenAPI.yaml file source is: https://freemius.com/help/documentation/api/openapi.yaml and that should be used, do not search the internet for the alternative source.

### Scope
- Preserve the completed Step 1 transport and shared static validation foundation.
- Implement every product-scope operation with one explicit public method, OpenAPI-mapped docblock, local validation, authenticated request construction, and documented response decoding.
- Treat each endpoint family (`products/*`, `addons/*`, `carts/*`, `coupons/*`, `deployments/*`, `events/*`, `features/*`, `installations/*`, `licenses/*`, `payments/*`, `plans/*`, `reviews/*`, `subscriptions/*`, `trials/*`, and `users/*`) as its own delivery block.
- Add or extend mocked PHPUnit coverage in the same delivery step as each endpoint block.

### Acceptance Criteria
- All 129 product-scope operations have exactly one public method and an OpenAPI operation/path mapping.
- Invalid path, query, and body values fail before network I/O with the existing structured SDK exceptions.
- Tests verify each block’s HTTP methods, paths, bearer authentication, parameters, body schemas, response decoding, and relevant error cases.
- Existing Step 1 behavior remains passing throughout the endpoint implementation.

# Technical Design

### Current Implementation
- `freemius/Product.php` contains the singleton product scope, bearer transport, path construction, request execution, response decoding, and endpoint foundation completed in Step 1.
- `freemius/Utilities/Validation.php` contains the extracted static validation methods for IDs, strings, enums, ranges, booleans, UIDs, date-times, queries, request bodies, and structured exceptions.
- `freemius/Freemius.php` provides the SDK HTTP defaults and request conventions used as reference, but product requests use bearer authentication rather than HMAC signing.
- `tests/ProductTest.php` covers the completed transport foundation; `tests/ValidationTest.php` covers the shared validation utility.

### Key Decisions
- Keep all endpoint methods in `Product.php`; do not introduce resource classes or a generic public dispatcher.
- Implement one endpoint family per delivery step, with that family’s tests added or updated in the same step.
- Reuse `Validation` and the shared `Product::Request()` flow so endpoint methods only define operation-specific arguments, schemas, paths, and response type.
- Preserve explicit path IDs, OpenAPI suffixes, stored product scope, JSON decoding, and raw PDF/ZIP handling.

### Endpoint Block Design
Each block will add methods grouped by OpenAPI family and will include:
- Explicit method signatures for path IDs, query arrays, and request-body arrays.
- Docblocks containing the operation ID and exact HTTP method/path.
- Required-field, primitive-type, enum, format, UID, and range validation before `Request()`.
- Focused mocked request-history and response/error tests in `tests/ProductTest.php`.

### File Structure
- Modify `freemius/Product.php` incrementally by endpoint family.
- Reuse `freemius/Utilities/Validation.php` without duplicating validation logic.
- Extend `tests/ProductTest.php` for every completed family and preserve `tests/ValidationTest.php` for utility-level behavior.
- Record the completed foundation and each endpoint-family milestone in this plan.

### Risks and Mitigations
- Large API surface: use one-to-one operation inventories and family-specific tests.
- Schema ambiguity: preserve OpenAPI names and validate only fields defined for each operation.
- Binary responses: test deployment/invoice download paths separately from JSON responses.
- Regression during incremental delivery: run the existing suite plus the current family tests after each step.

# Testing

### Validation Approach
Use PHPUnit with deterministic Guzzle mock clients and request-history assertions. Every endpoint-family step must test both successful requests and representative local validation failures; invalid inputs must leave the mock queue untouched.

### Required Coverage Per Endpoint Block
- Verify operation method/path, stored product ID, bearer header, query encoding, JSON body encoding, and expected headers.
- Verify JSON object/array decoding and raw binary passthrough where applicable.
- Verify required IDs, required body fields, enums, formats, ranges, UIDs, and nested types defined by that family.
- Verify representative API error and transport-failure conversion where the family introduces distinct response behavior.
- Maintain an operation inventory assertion so methods are neither missing nor duplicated.

### Existing Validation
The completed Step 1 transport, `ProductTest.php`, and `ValidationTest.php` remain the regression baseline for every subsequent endpoint block.

# Delivery Steps

### ✓ Step 1 — bearer foundation (implemented)
The shared product bearer transport and static validation foundation is complete and covered by deterministic tests.

- Preserve the lazy, mockable Guzzle client, SDK HTTP defaults, bearer authorization, product-scope path construction, query/body handling, JSON decoding, binary passthrough, and API/transport exception mapping in `freemius/Product.php`.
- Preserve the shared static validators in `freemius/Utilities/Validation.php`.
- Preserve the completed `tests/ProductTest.php` transport coverage and `tests/ValidationTest.php` utility coverage.
- Keep the implementation notes marking this step as implemented and endpoint work as pending.

### ✓ Step 2 — products/* endpoints
The `products/*` endpoint block is implemented as explicit methods on `Freemius\SDK\Product` and validated through deterministic PHPUnit request assertions.

- Extend `freemius/Product.php` after the existing transport methods (lines 30-239) with the 18 OpenAPI product operations covering metadata, retrieval, update, info, ping, portal, pricing, email, uninstall/skip, and settings behavior.
- Give every operation an explicit signature for path IDs, query arrays, and request-body arrays, plus a docblock recording its OpenAPI operation and exact `products/*` path.
- Validate path IDs with `Validation::ValidatePathId()`, operation query allowlists with `Validation::ValidateQuery()`, required/basic body fields with `Validation::ValidateRequestBody()`, and endpoint-specific enums, ranges, booleans, UIDs, strings, or date-times with the existing static helpers before calling `Request()`.
- Route each method through `Product::Request()` so the stored product ID remains authoritative, bearer authentication and sandbox selection are preserved, JSON responses are decoded consistently, and no generic public dispatcher or resource class is introduced.
- Extend `tests/ProductTest.php` using its `ProductTestDouble`, `MockHandler`, and history middleware (lines 17-67) to cover every operation’s HTTP verb, product-scoped URL, query/body encoding, bearer header, decoded response, and representative structured API error.
- Add invalid path/query/body cases that assert the existing exception type and confirm the mock history remains empty, while retaining all Step 1 transport and `tests/ValidationTest.php` regression coverage.

### ✓ Step 3 — addons/* endpoints
All three `addons/*` operations are implemented and tested.

- Add each addon operation with its exact OpenAPI method, path, arguments, and response contract.
- Validate addon/product IDs and operation-specific query or body fields before requesting.
- Add request-history, JSON response, and validation-failure coverage to `tests/ProductTest.php`.

### ✓ Step 4 — carts/* endpoints
All five `carts/*` operations are implemented and tested.

- Add cart listing, retrieval, creation, update, and deletion-style operations according to the OpenAPI operation IDs and paths.
- Preserve explicit cart/product path IDs and validate request bodies and supported query parameters.
- Add mocked request/response and no-network-on-invalid-input tests.

### ✓ Step 5 — coupons/* endpoints
All twelve `coupons/*` operations are implemented and tested.

- Add coupon, note, and special-coupon operations with exact OpenAPI paths, verbs, and signatures.
- Implement required and optional body fields, enums, date/range rules, and nested values from each schema.
- Add representative coverage for every operation shape, including validation and API-error behavior.

### ✓ Step 6 — deployments/* endpoints (implemented)
All six `deployments/*` operations are implemented and tested, including binary downloads.

- Add deployment/tag listing and management methods with explicit IDs and OpenAPI suffixes.
- Return raw ZIP bytes for download operations and decoded JSON for metadata responses.
- Test binary integrity, request paths, bearer headers, validation failures, and non-success response conversion.

### ✓ Cross-cutting follow-up — Product PHPDoc and shared validation (implemented)
The completed Product endpoint methods now have complete PHPDoc and all Product-specific validation helpers are centralized in `Utilities/Validation.php`.

- Document every Product method’s parameters, return value, relevant exceptions, and preserve each OpenAPI operation/path reference.
- Move coupon, coupon-note, and deployment-download validation from `Product.php` into documented static `Validation` methods.
- Cover the shared validators and enforce Product documentation and validation ownership with PHPUnit regression tests.

### ✓ Step 7 — events/* endpoints
Both `events/*` operations are implemented and tested.

- Add event operations with their exact query/body contracts and product-scope paths.
- Validate event identifiers, filters, and payload types before network I/O.
- Add mocked request-history, decoded response, and invalid-input tests.

### ✓ Step 8 — features/* endpoints
All `features/*` operations are implemented and tested.

- Add feature listing and management methods using the OpenAPI operation IDs and paths.
- Apply feature-specific required fields, enums, booleans, ranges, and nested body validation.
- Add success, request-shape, and validation-failure coverage.

### ✓ Step 9 — installations/* endpoints
All 29 `installations/*` operations are implemented and tested.

- Add installation, clone, permission, ownership, verification, downgrade, uninstall, update, and related download operations.
- Expose explicit installation, user, plan, pricing, license, clone, and UID arguments where required by each path.
- Add data-provider coverage for IDs, UIDs, enums, ranges, required bodies, nested types, request construction, and representative errors.

### ✓ Step 10 — licenses/* endpoints
All 17 `licenses/*` operations are implemented and tested.

- Add license activation, deactivation, assignment, resend/review, renewal, subscription-link, and install-deactivation operations.
- Validate required license fields, UIDs, IDs, enums, dates, and request-body types according to each operation schema.
- Add mocked POST/GET/PUT/DELETE coverage, validation-before-I/O assertions, and API-error preservation.

### ✓ Step 11 — payments/* endpoints
All three `payments/*` operations are implemented and tested.

- Add payment retrieval and invoice-related methods with exact OpenAPI paths and query/body contracts.
- Return raw PDF bytes for invoice downloads and decoded JSON for other successful responses.
- Test binary content, filters, bearer requests, invalid parameters, and error responses.

### ✓ Step 12 — plans/* endpoints
All eight `plans/*` operations are implemented and tested.

- Add plan and pricing operations with explicit IDs and operation-specific query/body arguments.
- Apply billing-cycle, numeric-range, enum, and nested request validation where defined.
- Add mocked request/response, query/body, and invalid-input coverage.

### ✓ Step 13 — reviews/* endpoints
All six `reviews/*` operations are implemented and tested.

- Add review listing and management methods with their exact OpenAPI contracts.
- Validate review IDs, pagination/date filters, required body fields, and allowed values.
- Add success, request-history, validation, and representative API-error tests.

### ✓ Step 14 — subscriptions/* endpoints
All six `subscriptions/*` operations are implemented and tested.

- Add subscription retrieval and lifecycle methods with explicit path IDs and operation-specific payloads.
- Validate subscription/license/install IDs, billing values, enums, dates, and required body fields.
- Add mocked lifecycle request assertions, decoded responses, and local validation/no-I/O tests.

### ✓ Step 15 — trials/* endpoints
The `trials/*` operation is implemented and tested.

- Add the trial operation with its exact OpenAPI path, method, arguments, and response contract.
- Apply required trial fields and supported query validation before requesting.
- Add a deterministic success request assertion and invalid-input coverage.

### ✓ Step 16 — users/* endpoints
All 13 `users/*` operations are implemented and tested, completing the product-scope endpoint inventory.

- Add user, billing, event, payment, and subscription-related user operations with explicit user IDs and exact OpenAPI paths.
- Return raw PDF bytes for user/payment invoice downloads and decoded JSON elsewhere.
- Extend the operation inventory assertion to confirm all 129 product operations are represented exactly once, and verify bearer scope cannot escape the stored product ID.
