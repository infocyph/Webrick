Negotiation Middleware
======================

Parses ``Accept``, ``Accept-Language``, ``Accept-Charset`` headers and resolves the best match for content negotiation.

--------------

Configuration
-------------

.. code:: php

   use Infocyph\Webrick\Middleware\NegotiationMiddleware;

   $preGlobal[] = new NegotiationMiddleware(
       produces: [
           '+json',                // Vendor JSON types (application/vnd.api+json, etc.)
           'application/json',
           'text/html',
           'text/plain',
           'application/xml',
           'text/xml'
       ],
       charsets: ['utf-8', 'iso-8859-1'],     // Supported charsets
       locales: ['en', 'es', 'fr', 'de', 'ja'], // Supported locales
       localeFallback: 'en'                    // Default when no match
   );

Constructor Parameters
~~~~~~~~~~~~~~~~~~~~~~

+--------------------+-------------------+--------------------+----------------------------------------------+
| Parameter          | Type              | Default            | Description                                  |
+====================+===================+====================+==============================================+
| ``produces``       | ``array<string>`` | ``['+json', ...]`` | Supported media types                        |
+--------------------+-------------------+--------------------+----------------------------------------------+
| ``charsets``       | ``array<string>`` | ``['utf-8']``      | Supported character encodings                |
+--------------------+-------------------+--------------------+----------------------------------------------+
| ``locales``        | ``array<string>`` | ``['en']``         | Supported locales (language codes)           |
+--------------------+-------------------+--------------------+----------------------------------------------+
| ``localeFallback`` | ``string``        | ``'en'``           | Default locale when client preference absent |
+--------------------+-------------------+--------------------+----------------------------------------------+

--------------

What It Does
------------

1. **Parses** ``Accept`` header → finds best media type match
2. **Parses** ``Accept-Charset`` → finds best charset match
3. **Parses** ``Accept-Language`` → finds best locale match
4. **Attaches** results as request attributes:

   - ``negotiated.type`` → ``'application/json'``
   - ``negotiated.charset`` → ``'utf-8'``
   - ``locale`` → ``'en'``

--------------

Using Negotiated Values
-----------------------

In Handlers with ``Response::auto()``
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   use Infocyph\Webrick\Request\Request;
   use Infocyph\Webrick\Response\Response;

   Route::get('/data', function (Request $r) {
       $data = ['items' => [1, 2, 3]];

       // Automatically chooses format based on negotiation
       return Response::auto($r, $data);
   });

**Client sends**:

::

   GET /data
   Accept: application/xml

**Response**:

.. code:: xml

   <data><items><item>1</item><item>2</item></items></data>

Manual Branching
~~~~~~~~~~~~~~~~

.. code:: php

   Route::get('/users', function (Request $r) {
       $users = UserRepository::all();

       $type = $r->getAttribute('negotiated.type');

       return match(true) {
           str_contains($type, 'json') => Response::json($users),
           str_contains($type, 'xml') => Response::create(
               $this->toXml($users),
               200,
               ['Content-Type' => 'application/xml']
           ),
           str_contains($type, 'html') => Response::create(
               $this->renderHtml($users),
               200,
               ['Content-Type' => 'text/html']
           ),
           default => Response::json($users)  // Fallback
       };
   });

Access Locale
~~~~~~~~~~~~~

.. code:: php

   Route::get('/greeting', function (Request $r) {
       $locale = $r->getAttribute('locale') ?? 'en';

       $greetings = [
           'en' => 'Hello',
           'es' => 'Hola',
           'fr' => 'Bonjour',
           'de' => 'Guten Tag'
       ];

       return Response::plaintext($greetings[$locale] ?? $greetings['en'], 200);
   });

--------------

Per-Route Media Type Restrictions
---------------------------------

Use ``#[Produces]`` attribute to limit acceptable types:

.. code:: php

   use Infocyph\Webrick\Router\Definition\Attribute\{Get, Produces};

   #[Get('/data.json', name: 'data.json')]
   #[Produces(types: ['application/json'])]
   public function getJson(): Response {
       return Response::json(['format' => 'json']);
   }

   #[Get('/data.xml', name: 'data.xml')]
   #[Produces(types: ['application/xml', 'text/xml'])]
   public function getXml(): Response {
       return Response::create('<data><format>xml</format></data>', 200, [
           'Content-Type' => 'application/xml'
       ]);
   }

   #[Get('/data', name: 'data')]
   #[Produces(types: ['application/json', 'application/xml', 'text/html'])]
   public function getData(Request $r): Response {
       $data = ['format' => 'flexible'];
       return Response::auto($r, $data);  // Negotiates
   }

**Behavior**: If client requests unsupported type, returns **406 Not Acceptable**.

--------------

Media Type Aliases
------------------

The middleware recognizes common aliases:

::

   +json → application/json
   +xml  → application/xml

**Example**:

::

   Accept: application/vnd.api+json

Matches:

.. code:: php

   produces: ['+json', 'application/json']

--------------

Quality Values (q-factor)
-------------------------

Client can specify preferences with quality values:

::

   Accept: text/html, application/xml;q=0.9, */*;q=0.8

**Priority**:

1. ``text/html`` (q=1.0, implicit)
2. ``application/xml`` (q=0.9)
3. ``*/*`` (q=0.8, catch-all)

Middleware picks the **highest q-value** that you support.

--------------

Language Negotiation
--------------------

::

   Accept-Language: fr-FR, fr;q=0.9, en;q=0.8, *;q=0.5

**Matching**:

1. Exact match: ``fr-FR``
2. Prefix match: ``fr``
3. Fallback: ``en`` (if in your ``locales``)
4. Ultimate fallback: ``localeFallback``

--------------

Request Attributes Set
----------------------

+------------------------+------------+------------------------+-----------------------+
| Attribute              | Type       | Example                | Description           |
+========================+============+========================+=======================+
| ``negotiated.type``    | ``string`` | ``'application/json'`` | Best media type match |
+------------------------+------------+------------------------+-----------------------+
| ``negotiated.charset`` | ``string`` | ``'utf-8'``            | Best charset match    |
+------------------------+------------+------------------------+-----------------------+
| ``locale``             | ``string`` | ``'en'``               | Best locale match     |
+------------------------+------------+------------------------+-----------------------+

--------------

Interplay with ``Vary`` Header
------------------------------

If you serve different content based on negotiation, **always** set ``Vary``:

.. code:: php

   return Response::json($data)
       ->withHeader('Vary', 'Accept, Accept-Language');

Or use **VaryAccumulatorMiddleware** (post-global) to manage this automatically.

--------------

Error Handling (406 Not Acceptable)
-----------------------------------

If middleware can't satisfy client's ``Accept`` header:

::

   HTTP/1.1 406 Not Acceptable
   Content-Type: application/json

   {
     "error": "Cannot produce requested media type",
     "acceptable": ["application/json", "text/html", "application/xml"]
   }

--------------

Best Practices
--------------

✅ **Do**
~~~~~~~~~

1. **List types in preference order**

   .. code:: php

      produces: ['application/json', 'text/html', 'application/xml']
      // Prefers JSON if client accepts multiple

2. **Use ``Response::auto()`` for flexibility**

   .. code:: php

      return Response::auto($r, $data);  // Adapts to client

3. **Set ``Vary`` when content differs**

   .. code:: php

      ->withHeader('Vary', 'Accept, Accept-Language')

4. **Provide sensible fallback**

   .. code:: php

      localeFallback: 'en'  // Most common

❌ **Don't**
~~~~~~~~~~~~

1. **Don't ignore negotiation results**

   .. code:: php

      // ❌ Always returns JSON regardless of Accept
      return Response::json($data);

      // ✅ Respects negotiation
      return Response::auto($r, $data);

2. **Don't support formats you can't produce**

   .. code:: php

      // ❌ Says you support XML but can't generate it
      produces: ['application/json', 'application/xml']
      // Handler only does: Response::json(...)

3. **Don't forget ``Vary`` header**

   .. code:: php

      // ❌ Cacheable but varies by Accept
      return Response::json($data)
          ->withHeader('Cache-Control', 'public, max-age=60');

      // ✅ Correct
      return Response::json($data)
          ->withHeader('Cache-Control', 'public, max-age=60')
          ->withHeader('Vary', 'Accept');

--------------

Troubleshooting
---------------

Issue: Always returns JSON
~~~~~~~~~~~~~~~~~~~~~~~~~~

**Cause**: Handler doesn't use ``Response::auto()`` or manual branching.

**Fix**:

.. code:: php

   // Before
   return Response::json($data);

   // After
   return Response::auto($r, $data);

Issue: Wrong locale selected
~~~~~~~~~~~~~~~~~~~~~~~~~~~~

**Cause**: Locale not in ``locales`` array.

**Debug**:

.. code:: php

   $requested = $r->getHeaderLine('Accept-Language');
   $resolved = $r->getAttribute('locale');
   error_log("Requested: {$requested}, Resolved: {$resolved}");

**Fix**: Add missing locale:

.. code:: php

   locales: ['en', 'es', 'fr', 'de', 'ja', 'pt']  // Added 'pt'

Issue: 406 errors in production
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

**Cause**: Client sends ``Accept`` header your app doesn't support.

**Solution**: Add wildcard fallback:

.. code:: php

   produces: ['application/json', 'text/html', '*/*']  // Catch-all

Or handle 406 gracefully:

.. code:: php

   // In error handler
   if ($status === 406) {
       return Response::json(['error' => 'Not acceptable'], 406);
   }

--------------

Summary
-------

**Negotiation middleware is essential for**:

- ✅ RESTful APIs serving multiple formats
- ✅ Internationalized applications
- ✅ Progressive enhancement (HTML → JSON for AJAX)

**Key decisions**:

1. **Produces**: List all formats you can generate
2. **Locales**: List all translations you support
3. **Fallback**: Choose the most common locale
4. **Vary**: Always set when content differs

**Golden rule**: If you negotiate, respect the result and set ``Vary``.
