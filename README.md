# ORM Module Documentation

The ORM (Object-Relational Mapping) module for ModEl Framework provides a powerful system for working with database entities in an object-oriented way. It consists of two main components: the `ORM` class for managing elements and the `Element` class representing individual database records.

## Table of Contents

1. [Overview](#overview)
2. [ORM Class](#orm-class)
3. [Element Class](#element-class)
4. [Relationships](#relationships)
5. [Usage Examples](#usage-examples)
6. [Advanced](#advanced)

---

## Overview

The ORM module allows you to:
- Map database tables to PHP classes
- Work with database records as objects
- Define and manage relationships between entities
- Automatically load related data efficiently
- Handle CRUD operations with ease
- Support multilingual content
- Manage file attachments
- Implement auto-increment and ordering fields

### Key Concepts

- **Element**: A class representing a single database record (row)
- **ORM**: The factory/manager class for creating and retrieving elements
- **Relationships**: Define how elements relate to each other (has-many, belongs-to, many-to-many)

---

## ORM Class

The `ORM` class (`Model\ORM\ORM`) is the main entry point for creating and retrieving elements.

### Main Methods

#### `one(string $element, $where = false, array $options = []): Element|bool`

Creates or retrieves a single Element instance.

**Parameters:**
- `$element` (string): Element class name (short name or fully qualified)
- `$where` (int|array|bool): 
  - Numeric ID to load
  - Array of WHERE conditions
  - `false` to create a new element
- `$options` (array): Additional options
  - `model`: Model instance
  - `table`: Override default table
  - `clone`: Don't use object cache
  - `idx`: Module ID
  - `assoc`: Association data for many-to-many

**Returns:** Element instance or `false` if not found

**Examples:**
```php
// Load by ID
$user = $model->_ORM->one('User', 42);

// Load by conditions
$user = $model->_ORM->one('User', ['email' => 'user@example.com']);

// Create new element
$user = $model->_ORM->one('User', false);

// Check if found
if ($user === false) {
	echo "User not found";
}
```

#### `create(string $element, array $options = []): Element`

Creates a new Element instance (shorthand for `one($element, false, $options)`).

**Parameters:**
- `$element` (string): Element class name
- `$options` (array): Creation options

**Returns:** New Element instance

**Example:**
```php
$user = $model->_ORM->create('User');
$user->save([
    'name' => 'John Doe',
    'email' => 'john@example.com',
]);
```

#### `all(string $element, array $where = [], array $options = []): array|\Generator`

Retrieves multiple elements.

**Parameters:**
- `$element` (string): Element class name
- `$where` (array): WHERE conditions
- `$options` (array): Query options
  - `table`: Override default table
  - `stream`: Return generator instead of array (default: true)
  - `order_by`: Order by field(s)
  - `limit`, `offset`: Pagination
  - `joins`: JOIN clauses
  - And all other Db module options

**Returns:** Generator (if stream=true) or array of Elements

**Examples:**
```php
// Get all active users (as generator)
foreach ($model->_ORM->all('User', ['active' => 1]) as $user) {
	echo $user['name'];
}

// Get as array with limit
$users = $model->_ORM->all('User', [], [
	'stream' => false,
	'limit' => 10,
	'order_by' => 'created_at DESC',
]);

// With complex conditions
$users = $model->_ORM->all('User', [
	'status' => 'active',
	'created_at' => ['>', '2024-01-01'],
], [
	'stream' => false,
]);
```

#### `count(string $element, array $where = [], array $options = []): int`

Counts elements matching criteria.

**Parameters:**
- `$element` (string): Element class name
- `$where` (array): WHERE conditions
- `$options` (array): Query options

**Returns:** Count of matching elements

**Example:**
```php
$activeUsers = $model->_ORM->count('User', ['active' => 1]);
```

### Object Caching

The ORM automatically caches Element instances to prevent duplicate objects for the same database record. This ensures:
- Memory efficiency
- Data consistency (changes to one instance affect all references)

You can bypass caching with the `clone` option:
```php
$user1 = $model->_ORM->one('User', 1);
$user2 = $model->_ORM->one('User', 1, ['clone' => true]); // Different instance
```

---

## Streaming

All reads performed with "all" method are streamed by default. This means that the results are returned as a generator, which is more memory efficient than returning an array.

If you need to return an array, you can set the "stream" option to false.

```php
$users = $model->_ORM->all('User', [], ['stream' => false]);
```


## Element Class

The `Element` class (`Model\ORM\Element`) represents a single database record.

### Static Properties

Define these in your Element subclass:

```php
class User extends Element
{
	public static ?string $table = 'users';
	public static ?string $controller = 'user-page';
	public static array $fields = [
		'avatar' => [
			'type' => 'file',
			'path' => 'users/[id]/avatar.[ext]',
		],
		'bio' => [
			'type' => 'textarea',
		],
	];
}
```

- `$table`: Database table name (this is the only required property)
- `$controller`: Associated controller for URL generation (optional)
- `$fields`: Field definitions for forms and files (fields are retrieved automatically from the database, here you can override their type or settings)

### Data Access

Elements implement `ArrayAccess`, so you can access data like an array:

```php
$user = $model->_ORM->one('User', 1);

echo $user['name'];
echo $user['email'];

// Check if exists
if (isset($user['phone'])) {
	echo $user['phone'];
}
```

### Main Methods

#### `load(?array $options = null): void`

Loads element data from database. Called automatically when accessing data, no need to call it manually.

**Options:**
- Custom options that can be used in `beforeLoad()` and `afterLoad()` hooks

#### `exists(): bool`

Returns true if the element has already been persisted to the database.

#### `getData(bool $removePrimary = false): array`

Get all element data as array.

```php
$data = $user->getData();
$dataWithoutId = $user->getData(true);
```

#### `save(?array $data = null, array $options = []): int`

Save element to database.

**Options:**
- `children` (bool): Save children too
- `version` (int): Optimistic locking version
- `form` (Form): Use specific form instance
- `saveForm` (bool): Save form fields (files, etc.)
- `afterSave` (bool): Call afterSave hook (default: true)
- `log` (bool): Log the change (default: true)

**Returns:** Element ID

```php
// Save current data as it is
$userId = $user->save();

// Update and save in one call
$userId = $user->save([
	'name' => 'New Name',
	'email' => 'new@example.com',
]);
```

#### `delete(): bool`

Delete element from database.

```php
$user->delete();
```

#### `reload(): bool`

Reload data from database, discarding local changes.

```php
$user['name'] = 'Temp Change';
$user->reload();
echo $user['name']; // Original name from database
```

#### `duplicate(array $replace = []): Element`

Create a duplicate of the element.

**Parameters:**
- `$replace` (array): Fields to replace in the duplicate

```php
$newUser = $user->duplicate([
	'email' => 'copy@example.com',
]);
```

### Hooks

Override these methods in your Element subclass to add custom behavior:

#### `init(): void`
Called during element construction. Define relationships here.

```php
protected function init(): void
{
	$this->has('posts', [
		'element' => 'Post',
		'type' => 'multiple',
	]);
	
	$this->belongsTo('Company', [
		'field' => 'company_id',
	]);
}
```

#### `beforeLoad(array &$options): void`
Called before loading data. Modify options if needed.

#### `afterLoad(array $options): void`
Called after loading data.

#### `beforeUpdate(array &$data): void`
Called before updating internal data. Modify data if needed.

#### `afterUpdate(array $saving): void`
Called after updating internal data.

#### `beforeSave(array &$data): void`
Called before saving to database. Modify data if needed.

```php
protected function beforeSave(array &$data): void
{
	// Ensure email is lowercase
	if (isset($data['email'])) {
		$data['email'] = strtolower($data['email']);
	}
}
```

#### `afterSave(?array $previous_data, array $saving): void`
Called after successful save.

**Parameters:**
- `$previous_data`: Previous data (null if new element)
- `$saving`: Data that was saved

```php
protected function afterSave(?array $previous_data, array $saving): void
{
	if ($previous_data === null) {
		// New element was created
		$this->sendWelcomeEmail();
	} elseif (isset($saving['status']) && $saving['status'] !== $previous_data['status']) {
		// Status changed
		$this->notifyStatusChange();
	}
}
```

If you throw an exception in this hook, the save will be cancelled (as it is internally wrapped in a transaction).

#### `beforeDelete(): bool`
Called before deletion. Return false to prevent deletion.

```php
protected function beforeDelete(): bool
{
	if ($this['is_protected']) {
		return false; // Prevent deletion
	}
	return true;
}
```

#### `afterDelete(): void`
Called after successful deletion.
If you throw an exception in this hook, the deletion will be cancelled (as it is internally wrapped in a transaction).

---

## Relationships

Define relationships in the `init()` method using `has()` and `belongsTo()`.

### One-to-Many (Has Many)

```php
protected function init(): void
{
	$this->has('posts', [
		'type' => 'multiple', // Default
		'element' => 'Post',
		'field' => 'user_id', // Foreign key in posts table (default as the name of the current element lowercased and with underscores)
		'order_by' => 'created_at DESC',
		'save' => true, // Save children when parent is saved
	]);
}

// Usage
foreach ($user->posts as $post) {
	echo $post['title'];
}

// Count without loading
$postCount = $user->count('posts');

// Create new child
$newPost = $user->create('posts');
$newPost->save([
    'title' => 'New Post',
]);
```

### Many-to-One (Belongs To)

```php
protected function init(): void
{
	$this->belongsTo('Company', [
		'field' => 'company_id',
		'children' => 'users', // Optional: register this element in parent's children, to optimize loading
	]);
}

// Usage
echo $user->parent['name']; // Company name
```

### One-to-One (Has One)

```php
protected function init(): void
{
	$this->has('profile', [
		'type' => 'single',
		'element' => 'UserProfile',
		'field' => 'profile_id', // Foreign key in this table, defaults to the name of the linked element lowercased and with underscores
	]);
}

// Usage
echo $user->profile['bio'];
```

### Many-to-Many

```php
protected function init(): void
{
	$this->has('roles', [
		'type' => 'multiple',
		'element' => 'Role',
		'assoc' => [
			'table' => 'user_roles',     // Pivot table
			'parent' => 'user_id',       // FK to this element
			'field' => 'role_id',        // FK to related element
		],
	]);
}

// Usage
foreach ($user->roles as $role) {
	echo $role['name'];
}
```

### Relationship Options

All `has()` options:

- `type` (string): `'multiple'` or `'single'`
- `element` (string): Related element class
- `table` (string): Override related table
- `field` (string): Foreign key field
- `where` (array): Additional WHERE conditions
- `joins` (array): JOIN clauses
- `order_by` (string|array): Order by
- `save` (bool): Save children when parent saves
- `save-costraints` (array): Required fields for saving
- `assoc` (array): Many-to-many configuration
- `fields` (array): Field overrides for children
- `duplicable` (bool): Duplicate children when duplicating parent
- `primary` (string): Override primary key
- `beforeSave` (callable): Hook before saving child
- `afterSave` (callable): Hook after saving child
- `afterGet` (callable): Hook after loading children
- `custom` (callable): Custom function to return children

**Example with hooks:**
```php
$this->has('posts', [
	'type' => 'multiple',
	'element' => 'Post',
	'beforeSave' => function(array &$data) {
		$data['user_id'] = $this['id'];
	},
	'afterSave' => function(?array $previous_data, array $saving) {
		if ($previous_data === null) {
			// New post created
			$this->incrementPostCount();
		}
	},
]);
```

---

## Usage Examples

### Basic CRUD

```php
// Create
$user = $model->_ORM->create('User');
$userId = $user->save([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'status' => 'active',
]);

// Read
$user = $model->_ORM->one('User', $userId);
echo $user['name'];

// Saving
$user->save([
    'name' => 'Jane Doe',
]);

// Delete
$user->delete();
```

### Working with Relationships

```php
// Load user with automatic child loading optimization
$user = $model->_ORM->one('User', 1);

// Access children
foreach ($user->posts as $post) {
	echo $post['title'];
	
	// Access nested relationships
	foreach ($post->comments as $comment) {
		echo $comment['text'];
	}
}

// Create child
$newPost = $user->create('posts');
$newPost->save([
    'title' => 'My Post',
    'content' => 'Post content',
]);

// Count children
$postCount = $user->count('posts');
```

### Batch Loading

```php
// Load multiple users
$users = $model->_ORM->all('User', ['status' => 'active'], [
	'stream' => false,
	'limit' => 50,
]);

// Thanks to internal optimization, accessing children is efficient
foreach ($users as $user) {
	// All posts are loaded in just 1 query total
	foreach ($user->posts as $post) {
		echo $post['title'];
	}
}
```

### File Handling

```php
class User extends Element
{
	public static array $fields = [
		'avatar' => [
			'type' => 'file',
			'path' => 'users/[id]/avatar.[ext]',
			'accepted' => ['image/jpeg', 'image/png'],
		],
	];
}

// Check if file exists
if ($user->fileExists('avatar')) {
	$path = $user->getFilePath('avatar');
	echo '<img src="' . PATH . $path . '">';
}
```

### Form Integration

See "Form" module for details.

```php
// Get form for element
$form = $user->getForm();

// Render form
$form->render();
```

### Auto-Increment Fields

```php
class Post extends Element
{
	protected function init(): void
	{
		$this->belongsTo('User');
		
		// Auto-increment within each user
		$this->autoIncrement('order_num', [
			'depending_on' => ['user_id'], // Optional
		]);
	}
}

// When saved, order_num is automatically set
$post = $user->create('posts');
$post->save([
    'title' => 'New Post',
]);
echo $post['order_num']; // Auto-assigned: 1, 2, 3, etc.
```

### Ordering/Sorting Fields

```php
class MenuItem extends Element
{
	protected function init(): void
	{
		$this->orderBy('position', [
			'depending_on' => ['menu_id'], // Optional
		]);
	}
}

// Change order
$menuItem->changeOrder(5); // Move to position 5

// When deleted, other items automatically shift down
$menuItem->delete();
```

---

## Advanced 

### Element class example

```php
namespace Model\MyModule\Elements;

use Model\ORM\Element;

class User extends Element
{
	public static ?string $table = 'users';
	public static ?string $controller = 'user-profile';
	
	public static array $fields = [
		'avatar' => [
			'type' => 'file',
			'path' => 'users/[id]/avatar.[ext]',
		],
	];
	
	protected function init(): void
	{
		$this->has('posts', [
			'element' => 'Post',
			'type' => 'multiple',
		]);
		
		$this->belongsTo('Company');
	}
	
	protected function beforeSave(array &$data): void
	{
		if (isset($data['email'])) {
			$data['email'] = strtolower(trim($data['email']));
		}
	}
	
	protected function afterSave(?array $previous_data, array $saving): void
	{
		if ($previous_data === null) {
			$this->sendWelcomeEmail();
		}
	}
	
	public function sendWelcomeEmail(): void
	{
		// Custom method
		$this->model->_Mailer->send([
			'to' => $this['email'],
			'subject' => 'Welcome!',
			'template' => 'welcome',
		]);
	}
	
	public function getFullName(): string
	{
		return $this['first_name'] . ' ' . $this['last_name'];
	}
}
```

### URL generation

```php
// Element must have $controller defined
class Post extends Element
{
	public static ?string $controller = 'BlogPost';
}

// Generate URL
$url = $post->getUrl();
$url = $post->getUrl(['lang' => 'en']);
```

### Rendering Templates

```php
// Render element template (requires Output module)
$user->render(); // Looks for templates/elements/User.php
$user->render('card'); // Looks for templates/elements/User-card.php

// Return HTML instead of echoing
$html = $user->render(null, [], true);
```

### Duplication with Children

```php
class User extends Element
{
	protected function init(): void
	{
		$this->has('posts', [
			'element' => 'Post',
			'type' => 'multiple',
			'duplicable' => true, // Include in duplication
		]);
		
		$this->duplicableWith([
			'email' => '', // Clear email in duplicate
		]);
	}
}

$duplicate = $user->duplicate([
	'name' => $user['name'] . ' (Copy)',
]);
// Posts are automatically duplicated too
```

### Manual Cache Management

```php
// Clear object cache
$model->_ORM->emptyObjectsCache();

// Clear children loading cache
$model->_ORM->emptyChildrenLoadingCache();

// Reload children
$user->reloadChildren('posts');
```

### Working with multiple databases

```php
// Use different database connection
$user = $model->_ORM->one('User', 1, ['idx' => 'secondary']);

// In Element, access correct DB
$db = $this->getORM()->getDb(); // Uses element's idx
```

---

## Best Practices

1. **Relationships are to be defined only in `init()`**
2. **Override hooks instead of core methods** for custom behavior
3. **Use transactions** for complex multi-element operations
4. **Leverage `beforeSave` hooks** for data validation and normalization

---

## Performance tips

1. **Use streaming (generators)** for large datasets:
   ```php
   foreach ($model->_ORM->all('User') as $user) {
	   // Memory efficient
   }
   ```

2. **Batch operations benefit from "children loading cache"** automatically:
   ```php
   $users = $model->_ORM->all('User', [], ['stream' => false]);
   foreach ($users as $user) {
	   // All posts loaded in 1 query thanks to "children loading cache"
	   foreach ($user->posts as $post) { }
   }
   ```

3. **Use `count()` method** instead of loading and counting:
   ```php
   $count = $user->count('posts'); // Efficient
   $count = count($user->posts);   // Less efficient, unless the posts have already been loaded
   ```

4. **Avoid N+1 queries** - the ORM helps automatically, but be aware:
   ```php
   // Good: CLC optimizes this automatically
   foreach ($users as $user) {
	   foreach ($user->posts as $post) { }
   }
   
   // Bad: Loading users individually
   foreach ($userIds as $id) {
	   $user = $model->_ORM->one('User', $id);
	   // Not optimized
   }
   ```

---

## Troubleshooting

### Element not found
```php
$user = $model->_ORM->one('User', 999);
if ($user === false) {
	// Element doesn't exist
}
```

### Relationship not loading
- Check that `init()` method calls `has()` or `belongsTo()`
- Verify table and field names are correct
- Ensure foreign keys have correct values

### Changes not saving
- Check `beforeSave()` hook isn't modifying data incorrectly
- Verify `filterDataToSave()` detects changes (compares with `db_data_arr`)
- Ensure no exception is thrown during save

### Memory issues
- Use streaming: `['stream' => true]` (default)
- Clear caches periodically if needed
- Don't load entire large datasets into arrays
