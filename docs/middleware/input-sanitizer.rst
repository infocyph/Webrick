Input Sanitizer
===============

Tidy up common user input before your handlers read it. This middleware applies light, **lossless** normalization so controllers don’t repeat the same trimming and null/blank checks everywhere.

--------------

What it does
------------

- **Trims** leading/trailing ASCII whitespace from scalar string inputs
- **Normalizes empties** (optional): converts ``''`` → ``null`` for selected keys or globally
- **Collapses weird whitespace** (optional): convert typical non-breaking spaces to regular spaces
- **Strips control chars** (optional): removes ``\u0000``–``\u001F`` except ``\t``, ``\n``, ``\r``
- Leaves **binary/file** uploads and non-string values alone

..

   Aim is predictable input, not security by itself. Keep validation & escaping in your handlers or domain layer.

--------------

Wiring
------

Place it in **pre-global** after cookies/normalize-method, before negotiation/validators:

.. code:: php

   $preGlobal = [
     // ... hardening, telemetry, request-limits, throttle, cookies ...
     \Infocyph\Webrick\Middleware\NormalizeMethodMiddleware::class,
     new \Infocyph\Webrick\Middleware\InputSanitizerMiddleware(
       trim: true,
       emptyToNull: ['email','name'],   // array of keys, or true for all strings
       collapseNbsp: true,
       stripControls: true,
       maxDepth: 3                      // how deep to walk arrays
     ),
     // ... negotiation, response cache, cache validators ...
   ];

*(Adjust constructor names/options to your implementation.)*

--------------

What gets sanitized
-------------------

- **Query params** (``?q=  hello`` → ``"hello"``)
- **Form body** (``application/x-www-form-urlencoded``, ``multipart/form-data``)
- **JSON** (``{"name":"  Hasan  "}`` → ``{"name":"Hasan"}``)

Files (``$r->files()``) are never modified.

--------------

Accessing sanitized input
-------------------------

Your usual helpers now see normalized data:

.. code:: php

   Route::post('/signup', function (\Infocyph\Webrick\Request\Request $r) {
     // already trimmed/collapsed according to your settings
     $name  = $r->input('name');
     $email = $r->input('email');   // possibly null if emptyToNull applies
     return \Infocyph\Webrick\Response\Response::json(compact('name','email'));
   });

--------------

Configuration patterns
----------------------

Convert empties for selected fields only
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

For forms where empty strings have special meaning elsewhere, limit ``emptyToNull``:

.. code:: php

   new InputSanitizerMiddleware(emptyToNull: ['email','phone']);

Sanitize deeply nested JSON
~~~~~~~~~~~~~~~~~~~~~~~~~~~

Increase ``maxDepth`` if clients send nested objects:

.. code:: php

   new InputSanitizerMiddleware(maxDepth: 6);

Preserve precise whitespace
~~~~~~~~~~~~~~~~~~~~~~~~~~~

Disable ``collapseNbsp`` if you accept user-entered text that must retain non-breaking spaces (e.g., WYSIWYG inputs).

--------------

Best practices
--------------

- Keep sanitization **idempotent** and predictable; don’t rewrite meaning (e.g., don’t lowercase emails unless policy says so).
- Apply **validation** after sanitization (types, lengths, formats).
- Consider per-route overrides when some endpoints require raw input (e.g., HMAC-signed payloads)—skip sanitization there.

--------------

Troubleshooting
---------------

+---------------------------------+------------------------------+-------------------------------------------------------------------------------+
| Symptom                         | Likely cause                 | Fix                                                                           |
+=================================+==============================+===============================================================================+
| Client’s signature/HMAC fails   | Sanitizer changed whitespace | Disable for that route or set ``collapseNbsp:false``, ``stripControls:false`` |
+---------------------------------+------------------------------+-------------------------------------------------------------------------------+
| Empty strings suddenly ``null`` | ``emptyToNull`` too broad    | Limit to specific fields instead of ``true``                                  |
+---------------------------------+------------------------------+-------------------------------------------------------------------------------+
| Arrays not sanitized deeply     | Depth too low                | Raise ``maxDepth``                                                            |
+---------------------------------+------------------------------+-------------------------------------------------------------------------------+
| Rich text loses spacing         | NBSP collapsed               | Set ``collapseNbsp:false`` for those inputs                                   |
+---------------------------------+------------------------------+-------------------------------------------------------------------------------+

--------------

Checklist
---------

- ☐ Add InputSanitizer after NormalizeMethod in **pre-global**
- ☐ Choose conservative defaults (trim + minimal normalization)
- ☐ Limit ``emptyToNull`` to fields where ``null`` is semantically correct
- ☐ Exclude endpoints that require raw payload fidelity (signatures)
- ☐ Validate and escape downstream as usual
