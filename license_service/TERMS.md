# Terms & Conditions — License Service

Effective date: 2026-05-31

These Terms & Conditions ("Terms") govern your access to and use of License Service,
a Drupal 10/11 module that connects a Drupal website to the License Verification
Server and enforces role-based content access control via signed license tokens
(the "Service"), operated by AideaMaker ("AideaMaker," "we," "us," or "our"). By
installing the module, activating a license, or otherwise using the Service, you
("you," "your," or "Customer") agree to be bound by these Terms. If you do not
agree, do not install or use the Service.

> **Note:** This document is a protective starting draft and is not a substitute
> for review by a qualified attorney.

---

## 1. Acceptance of Terms

By installing, activating, or using the Service you confirm that you have read,
understood, and agree to these Terms. If you are entering into these Terms on
behalf of a company or other legal entity, you represent that you have authority
to bind that entity, in which case "you" refers to that entity.

## 2. Eligibility

You must be at least 18 years old and capable of forming a binding contract to use
the Service. You are responsible for ensuring that your use of the Service complies
with all laws and regulations that apply to you and your Drupal site.

## 3. Accounts and Security

- You are responsible for maintaining the confidentiality of your license key and
  any API credentials issued to you.
- You are responsible for all activity that occurs under your license, including
  all activations and site registrations associated with your key.
- You must notify us promptly of any unauthorized use of your license key or any
  suspected breach of security.
- We are not liable for any loss or damage arising from your failure to safeguard
  your credentials or license key.

## 4. Licenses and Permitted Use

- The Service is provided to you under a limited, non-exclusive, non-transferable,
  non-sublicensable license, subject to the seat limits, tier restrictions, and
  device/domain bindings associated with your license key.
- You may not exceed the seat or environment limits of your license, share access
  beyond the licensed scope, transfer a license key to a third party, or circumvent
  any technical enforcement, activation, or anti-piracy mechanism in the Service.
- You may not use the Service to build, offer, or resell a competing service or
  tool.
- We reserve the right to suspend, revoke, or refuse any license that we reasonably
  believe is being used in violation of these Terms.

## 5. License Key and Activation

- Activating the Service binds a license key to one Drupal environment (identified
  by a machine ID derived from the site's UUID). Each bound environment consumes
  one licensed seat.
- The Service periodically contacts the License Verification Server online to
  validate your license. If validation cannot be completed within the offline grace
  period (default: 24 hours, configurable by the site administrator), the Service
  may reduce or restrict access enforcement until a successful validation occurs.
- You are responsible for ensuring that your server can reach the License
  Verification Server for periodic re-validation. AideaMaker is not responsible
  for access disruptions caused by your network configuration, firewall rules, or
  server outages.
- When a subscription lapses or is cancelled, the Service may automatically
  downgrade the active license to the Free tier at the next validation cycle.

## 6. Fees, Billing, and Subscriptions

- Prices for paid tiers (Pro, Enterprise, and any other paid plans) are stated at
  the point of purchase and are exclusive of taxes unless otherwise noted. You are
  responsible for all applicable taxes.
- Subscription licenses renew automatically for successive terms at the
  then-current rate unless cancelled before the renewal date.
- By providing a payment method you authorize AideaMaker and its payment processors
  to charge the applicable fees, including recurring renewal fees, to that method.
- Payments are processed by third-party payment providers; your use of those
  providers is subject to their own terms.

## 7. No Refunds

**All sales are final. There are no refunds and no pro-rated refunds for
cancellations.** Cancelling a subscription stops future renewals but does not
entitle you to a refund of any amount already paid, in whole or in part, for the
current or any prior term. Fees paid are non-refundable to the maximum extent
permitted by applicable law, including in cases of partial-term cancellation,
non-use, or early termination.

## 8. Cancellation and Termination

- You may cancel a subscription at any time; cancellation takes effect at the end
  of the current paid term, and no refund is provided for the remaining period.
- We may suspend or terminate your license at any time for any violation of these
  Terms or any conduct we reasonably believe is harmful to the Service, other
  customers, or us.
- Upon termination, your right to use the Service and any associated license ceases
  immediately. The module may be left installed but will operate as if unlicensed
  once the license is revoked or the grace period expires.
- Provisions that by their nature should survive termination will survive,
  including sections on fees, disclaimers, limitation of liability, and
  indemnification.

## 9. Acceptable Use

You agree not to:

- Use the Service for any unlawful, infringing, or fraudulent purpose.
- Reverse engineer, decompile, or attempt to extract signing keys, token secrets,
  or proprietary enforcement logic from the Service, except to the extent expressly
  permitted by applicable law.
- Modify or patch the Service to bypass license validation, token verification,
  seat cap enforcement, or any other access-control mechanism.
- Interfere with or disrupt the integrity or performance of the License
  Verification Server, including by sending excessive or automated requests.
- Resell, redistribute, or commercially exploit the Service except as expressly
  authorized in writing.

## 10. Content Access Enforcement

The Service enforces content access restrictions on your Drupal site based on your
configured license levels, role mappings, and content rules. You acknowledge that:

- **Configuration is your responsibility.** AideaMaker provides the enforcement
  mechanism; you are responsible for the correctness of your role-to-level
  mappings, content rules, quota settings, and metered view limits. Misconfigured
  rules may grant or deny access contrary to your intent.
- **The module can deny or abstain, not guarantee access.** The Service integrates
  with Drupal's native access system. Other modules, custom code, or Drupal core
  behavior may interact with the Service's decisions. AideaMaker is not responsible
  for access outcomes resulting from interactions with third-party Drupal modules.
- **Metered views and quotas are best-effort.** View counters and create/edit
  quotas rely on Drupal's database layer. Concurrent requests, caching layers, or
  site failures may in rare cases lead to over- or under-counting. AideaMaker is
  not liable for enforcement inaccuracies caused by such conditions.
- **Bypass permission.** Any Drupal user with the `bypass license gate` permission
  is exempt from all enforcement. You are responsible for assigning that permission
  only to trusted administrators.

## 11. Intellectual Property

The Service, including all software, algorithms, token-contract specifications,
and associated documentation, is owned by AideaMaker LLC or its licensors and is
protected by intellectual property laws. These Terms do not grant you any
ownership rights. Except for the limited license expressly granted to use the
Service, all rights are reserved. The Drupal module code itself is distributed
under the GPL-2.0-or-later open-source license; the license key system, token
format, and License Verification Server are proprietary to AideaMaker LLC.

© 2026 AideaMaker LLC. All rights reserved. AideaMaker LLC, 10685-B Hazelhurst
Dr. # 38673, Houston, TX 77043.

## 12. Privacy and Data

- We collect and process the information necessary to provide the Service,
  including license key identifiers, machine IDs (derived from your Drupal site
  UUID and domain), activation timestamps, seat usage counts, and basic heartbeat
  data.
- We do not collect or store the content of your Drupal site, your end users'
  personal data, or the content of nodes or fields gated by the module.
- You are responsible for your own compliance with applicable privacy laws in
  connection with your use of the Service, including your Drupal site's data
  processing activities.
- We implement reasonable security measures, including Ed25519-signed tokens and
  TLS for all communications, but no method of transmission or storage is
  completely secure.

## 13. Third-Party Services

The Service communicates with the License Verification Server hosted by AideaMaker
and may integrate with third-party Drupal modules (such as the Key module for
secret storage). We are not responsible for the availability, accuracy, or conduct
of any third-party service, and your use of those services is governed by their own
terms and policies.

## 14. Disclaimer of Warranties

THE SERVICE IS PROVIDED "AS IS" AND "AS AVAILABLE" WITHOUT WARRANTIES OF ANY KIND,
WHETHER EXPRESS, IMPLIED, OR STATUTORY, INCLUDING WITHOUT LIMITATION THE IMPLIED
WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, TITLE, AND
NON-INFRINGEMENT. WE DO NOT WARRANT THAT THE SERVICE WILL BE UNINTERRUPTED, ERROR-
FREE, SECURE, OR THAT DEFECTS WILL BE CORRECTED. IN PARTICULAR, WE MAKE NO WARRANTY
THAT THE SERVICE WILL PREVENT ALL UNAUTHORIZED ACCESS TO YOUR CONTENT OR THAT
CONTENT-ACCESS ENFORCEMENT WILL BE FREE FROM ERRORS IN ALL CONFIGURATIONS.

## 15. Limitation of Liability

TO THE MAXIMUM EXTENT PERMITTED BY LAW, IN NO EVENT WILL AIDEAMAKER OR ITS
AFFILIATES, OFFICERS, EMPLOYEES, OR AGENTS BE LIABLE FOR ANY INDIRECT, INCIDENTAL,
SPECIAL, CONSEQUENTIAL, EXEMPLARY, OR PUNITIVE DAMAGES, OR FOR ANY LOSS OF PROFITS,
REVENUE, DATA, OR GOODWILL, ARISING OUT OF OR RELATED TO YOUR USE OF THE SERVICE,
INCLUDING WITHOUT LIMITATION ANY UNAUTHORIZED ACCESS TO CONTENT ON YOUR DRUPAL SITE,
ANY MISCONFIGURED ACCESS RULES, OR ANY FAILURE OF METERED ENFORCEMENT. OUR TOTAL
AGGREGATE LIABILITY FOR ANY CLAIM ARISING OUT OF OR RELATING TO THESE TERMS OR THE
SERVICE WILL NOT EXCEED THE AMOUNT YOU PAID TO US FOR THE SERVICE IN THE TWELVE (12)
MONTHS PRECEDING THE EVENT GIVING RISE TO THE CLAIM.

## 16. Indemnification

You agree to indemnify, defend, and hold harmless AideaMaker and its affiliates
from and against any claims, liabilities, damages, losses, and expenses, including
reasonable legal fees, arising out of or in any way connected with your use of the
Service, your Drupal site's configuration of access rules, your violation of these
Terms, or your violation of any rights of a third party.

## 17. Changes to the Service or Terms

We may modify the Service or these Terms at any time. Material changes will be
posted with an updated effective date. Your continued use of the Service after
changes take effect constitutes acceptance of the revised Terms.

## 18. Governing Law and Disputes

These Terms are governed by the laws of the jurisdiction in which AideaMaker is
established, without regard to its conflict-of-laws principles. You agree that any
dispute arising out of or relating to these Terms or the Service will be resolved
in the courts located in that jurisdiction, and you consent to their personal
jurisdiction.

## 19. Severability and Waiver

If any provision of these Terms is held to be unenforceable, that provision will be
limited or eliminated to the minimum extent necessary, and the remaining provisions
will remain in full force and effect. Our failure to enforce any right or provision
will not be deemed a waiver of that right or provision.

## 20. Entire Agreement

These Terms constitute the entire agreement between you and AideaMaker regarding
the Service and supersede all prior agreements and understandings. Any
product-specific license terms or end-user license agreements presented at
activation are incorporated by reference.

## 21. AI Token Quota Enforcement

If you enable the **License Service: Token Limits** sub-module, the following
additional terms apply to the AI token quota enforcement feature:

- **Quota enforcement is a hard block.** When a user's cumulative AI token usage
  for a configured billing period meets or exceeds their license-level quota, all
  subsequent AI generation calls from that user are blocked until the period resets.
  There is no warn-only mode for token quotas.
- **Configuration is your responsibility.** You are responsible for setting
  appropriate token quotas and billing periods for each license level. AideaMaker
  provides the enforcement mechanism; AideaMaker is not responsible for business
  impacts resulting from quota settings that are too restrictive, too permissive, or
  incorrectly configured for your use case.
- **Quota counts are best-effort.** AI token usage figures are derived from the
  underlying token-counter module's aggregation tables. Concurrent AI requests,
  caching layers, or site failures may in rare cases result in over- or
  under-counting of tokens used. AideaMaker is not liable for enforcement
  inaccuracies caused by such conditions.
- **Bypass permission.** Any Drupal user with `bypass ai token usage limits` or
  `bypass license gate` is fully exempt from token quota enforcement. You are
  responsible for assigning those permissions only to trusted administrators.
- **License-envelope dependency.** Token quota enforcement is active only when your
  site license's signed entitlement grants the `quotas` feature. If your license
  tier does not include quota enforcement, the sub-module loads but enforces nothing.
  AideaMaker may change which tiers include quota enforcement in future license
  updates.

## 22. Contact

Questions about these Terms may be directed to AideaMaker through
[www.licenseverificationserver.com](https://www.licenseverificationserver.com).
