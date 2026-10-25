<div align="center">

# Validated Field for ACF

### Better data in. Less cleanup later.

Give your ACF fields input masks, custom validation and duplicate checks.<br>
Then manage your records in WordPress with editable tables and team workflows.

<p>
  <a href="#development-status"><img src="https://img.shields.io/badge/2.0.0-in_development-d97706?style=flat-square" alt="Version 2.0.0 in development"></a>
  <a href="#requirements"><img src="https://img.shields.io/badge/WordPress-6.0%2B-21759b?style=flat-square" alt="Declared WordPress minimum: 6.0"></a>
  <a href="#requirements"><img src="https://img.shields.io/badge/ACF-6.0%2B-00b9aa?style=flat-square" alt="Declared ACF minimum: 6.0"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-GPL--2.0--or--later-52525b?style=flat-square" alt="GPL 2.0 or later license"></a>
</p>

**[Get started](#get-started)** &nbsp; · &nbsp; **[Explore Pro](#build-more-with-validated-field-pro)** &nbsp; · &nbsp; [Compare editions](#free-or-pro) &nbsp; · &nbsp; [Plugin reference](readme.txt)

</div>

---

ACF gives your content structure. Validated Field adds the rules that make that content useful: a product code with the right format, a reference number that isn't already in use, or a field an editor can see but can't change.

Start with the free plugin for field validation and everyday record management. Add **Validated Field Pro** when your team needs calculations, approval workflows and advanced data tools.

> [!IMPORTANT]
> **2.0 is in development.** This README describes the unreleased Free and Pro builds. The [WordPress.org listing](https://wordpress.org/plugins/validated-field-for-acf/) distributes an older release without the new workspace and Pro tools. See [development status](#development-status) before trying this build.

## Make every field work harder

Configure rules where you already work, in the ACF Field Group editor.

- **Guide input as people type.** Masks help editors enter phone numbers, reference codes and other structured text in a consistent format.
- **Check values before saving.** Use regular expressions for patterns or PHP for custom business rules, with an error message that tells the editor what to fix.
- **Catch duplicate values.** Check uniqueness across a post type, a specific field or an individual record. Choose which post statuses count.
- **Protect values editors shouldn't change.** Read-only fields display existing information while blocking form edits.
- **Keep the ACF inputs you know.** Wrapped inputs retain their native settings, formatting and validation. Use Validated Field children inside native groups, repeaters and flexible-content layouts when those ACF field types are available.

### A rule in minutes

Need product codes like `SKU-12345`? Add a **Validated Field**, choose **Text** as the input type and select **Regular expression** as the validator. Enter this rule without delimiters:

```regex
^SKU-[0-9]{5}$
```

Set the error message to `Use SKU- followed by five digits, for example SKU-12345.` Enable a unique-value scope if each product needs its own code.

Masks help people enter a value. Server-side rules check whether it can be saved. Use both when the format matters.

## Give your team a better way to manage records

Free also includes a data workspace inside WordPress. Create private application objects for records such as contacts, inventory or service requests, or opt existing posts, pages and custom post types into ACF tables.

Edit simple values inline, create records with Quick Add, search and filter your list, and arrange columns to suit your work. Each person can choose their own column order, visibility and widths. Basic CSV export and filtered audit history are included.

Opting existing content into a table preserves its native editor, visibility and ACF field identities. Workspace edits use the shared permission and validation services.

## Build more with Validated Field Pro

When managing records becomes a daily team process, Pro adds the tools to handle the work around them. It installs alongside Free and uses the same records, permissions and validation services.

### Calculate values and automate the next step

Use formulas for calculated fields and rollups to summarize related records. Add automation, approvals and status transitions to move work through a defined process. Visual tools help you configure advanced rules, access policies, relationships and layouts.

### Give each team the view it needs

Create shared views, assign them to roles or users, and choose defaults. Combine nested AND/OR filters, relative dates and multiple sort priorities. Show metrics across the filtered result and use conditional formatting to flag records that need attention. Rich inline editors add support for media, relationships and structured values when the underlying ACF field type is installed.

### Handle data maintenance with a preview

Preview bulk changes before running them in the background. Import data, run background exports and save export profiles for recurring work. Quality scans find missing or invalid values, broken references and duplicates. Audit restore and bulk recovery help you revisit changes, subject to current permissions and record-version checks.

Background table jobs support up to 2,000 records per preview and 5 MiB CSV output. Preview and result snapshots expire after 24 hours; your records and audit history remain.

### Connect your records to a custom frontend

Generate record GraphQL APIs through WPGraphQL and client types for headless applications. Pro also adds API preview tools and configuration preflight checks for reviewing changes before applying them. Install WPGraphQL separately to use the GraphQL integration.

**[Explore Validated Field Pro at Doublesharp →](https://doublesharp.com/)**

Validated Field Pro is a separate add-on from the same developer. **ACF Pro is a different product** and is only needed for ACF's own paid field types. Neither Validated Field edition includes ACF.

## Free or Pro?

Free includes the field validation and everyday editing tools. Choose Pro for calculations, team workflows and advanced operations.

| What you need | Free | With Pro |
| :--- | :---: | :---: |
| Input masks, regex/PHP validation, uniqueness and read-only fields | Included | Included |
| Basic object and field editing, native rules and relationships | Included | Included |
| Record permissions, validation and audit history | Included | Included |
| Opt-in tables for existing posts, pages and custom post types | Included | Included |
| Simple inline editing, Quick Add and personal columns | Included | Included |
| Basic filters and CSV/configuration export | Included | Included |
| Formulas, rollups, automation and approvals | | Included |
| Advanced visual rule, policy and layout tools | | Included |
| Rich inline editors and shared, assigned or default views | | Included |
| Nested filters, multiple sorts, metrics and conditional formatting | | Included |
| Background bulk changes, exports and quality scans | | Included |
| Imports, audit restore and configuration preflight/apply | | Included |
| Generated record GraphQL APIs and client types | | Included |

## Get started

### Requirements

The 2.0 development build declares WordPress **6.0+**, PHP **8.0+** and Advanced Custom Fields **6.0+**. Install ACF separately. ACF Free and ACF Pro both work with either Validated Field edition; ACF's paid field types still require ACF Pro.

### Try the development build

Use a test site with a database backup. Clone this public repository into your WordPress plugins directory, keeping the folder name `validated-field-for-acf`, then activate **Advanced Custom Fields: Validated Field** in WordPress.

```sh
git clone --branch main https://github.com/doublesharp/validated-field-for-acf.git \
  wp-content/plugins/validated-field-for-acf
```

1. Install and activate ACF, then activate Validated Field.
2. Open an ACF field group and add a **Validated Field**.
3. Choose its **Input type**, then configure validation, uniqueness, masks or read-only behavior.
4. Save the field group and try both a valid and an invalid value on a matching record.

Global options are under **Settings → Validated Field**. Open **Validated Field** in the WordPress admin menu to explore the data workspace. If you also have the Pro add-on, install and activate it alongside Free.

## A few things worth knowing

<details>
<summary><strong>Do I need ACF Pro?</strong></summary>

No. Validation and the workspace can use ACF Free. ACF Pro is needed only for ACF's own paid field types, such as repeaters and flexible content. Buying Validated Field Pro does not include an ACF Pro license.

</details>

<details>
<summary><strong>What happens if I remove Pro or my license expires?</strong></summary>

License expiration does not disable installed Pro functionality. Entitlement governs future paid downloads and updates.

Removing Pro preserves records, field definitions, saved views, audit history and queued work. Free blocks affected writes that depend on missing Pro services. Restoring Pro makes those services available again; parked jobs require explicit administrator review and retry.

</details>

<details>
<summary><strong>Can I keep my existing validated fields?</strong></summary>

The development build retains the original `validated_field` type, saved settings, field keys and metadata names. The WordPress.org slug and plugin folder remain `validated-field-for-acf`, with `validated_field.php` as the entry point. Back up and test your existing forms before upgrading. ACF 4/5 integration is retired.

</details>

<details>
<summary><strong>What should developers know about validation?</strong></summary>

PHP validation rules execute trusted administrator code. Ordinary ACF field uniqueness is application-level validation, not a database unique constraint. Managed application records use transactional unique claims and version checks.

ACF forms and supported REST writes receive validation checks. Direct `update_field()` calls remain low-level APIs; custom writers must validate explicitly. Use the Records service for managed application records.

The current ACF 6+ adapter is [`includes/class-acf-field-validated-field.php`](includes/class-acf-field-validated-field.php). Git checkouts may contain dormant legacy assets; release archives use reviewed packaging manifests.

</details>

## Development status

Version **2.0.0 is unreleased**. The Free and Pro capabilities above are development functionality, not a claim of production readiness or public Pro availability. Current-runtime checks do not establish support across every declared minimum version, complete PHP branch coverage or compatibility with every third-party integration.

For the plugin reference and changelog, see [`readme.txt`](readme.txt). Report reproducible bugs through [GitHub Issues](https://github.com/doublesharp/validated-field-for-acf/issues), including your WordPress, PHP, ACF and plugin versions.

---

<div align="center">

Built by [Doublesharp](https://doublesharp.com/) · [WordPress.org listing](https://wordpress.org/plugins/validated-field-for-acf/) · [GPL-2.0-or-later](LICENSE)

**[Start with Free](#get-started) · [Build more with Pro](#build-more-with-validated-field-pro)**

</div>
