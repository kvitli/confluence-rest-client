# confluence-rest-client
Library to interact with the Confluence REST API

## Usage
Install using `Composer`:
```
composer require kvitli/confluence-rest-api
```
Sample code:
```php
# Creating Confluence connection with parameters loaded from .env file
$cf = new Confluence();
# Enable (somewhat more) detailed logging
$cf->set_debug(true);

# Get page id by space SPC and My sample page
$cf->get_page_id_by_title('SPC', 'My sample page');

# Update/create page My sample page in space SPC.
# $page_content must be valid XHTML
# $parent_page_id must be a page id
$cf->update_or_create_page('SPC', 'My sample page', $page_content, $parent_page_id);

$ Upload an attachment to a page
$cf->upload_attachment('/path/to/file', $page_id);

# Create a JIRA Link
$link = new Kvitli\Link('JIRA', $jira_key, $jira_key);

# Create an Info macro
$info = new Kvitli\Macro('info');

# Add a parameter
$info->add_parameter('atlassian-macro-output-type', 'INLINE');
# Add JIRA link to Macro as body
$info->set_body('<p>My JIRA LINK: '.$link->get_storage_format().'</p>');

# Create an Excerpt macro
$excerpt = new Kvitli\Macro('excerpt');
# Added Info macro to Excerpt macro
$excerpt->set_body($info->get_storage_format());

```

## Setup development environment

```
git clone $repository
composer install
```
Run tests
```
php tests/tests.php
```
Generate phpdoc
```
phpdoc -d ./src -t ./docs/
```
