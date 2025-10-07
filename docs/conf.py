# docs/conf.py — Webrick – PHP Router
# Sphinx 8.x compatible, Markdown-first, PHP-aware

from __future__ import annotations
import os
import sys
import datetime
from subprocess import Popen, PIPE

# --- Project info ------------------------------------------------------------
project   = "Webrick – PHP Router"
author    = "A. B. M. Mahmudul Hasan (infocyph)"
year_now  = datetime.date.today().strftime("%Y")
copyright = f"2021-{year_now}, infocyph"

def _get_version() -> str:
    # Read the RTD build version if present
    if os.environ.get("READTHEDOCS") == "True":
        v = os.environ.get("READTHEDOCS_VERSION")
        if v:
            return v
    try:
        pipe = Popen("git rev-parse --abbrev-ref HEAD", stdout=PIPE, shell=True, universal_newlines=True)
        branch = (pipe.stdout.read() or "").strip()
        return branch or "latest"
    except Exception:
        return "latest"

version = _get_version()
release = version
language = "en"

# --- Paths -------------------------------------------------------------------
# If you need to document Python modules, add paths here (not needed for PHP).
# sys.path.insert(0, os.path.abspath(".."))

# --- Extensions --------------------------------------------------------------
extensions = [
    # Markdown (MyST)
    "myst_parser",
    # Useful core
    "sphinx.ext.autodoc",
    "sphinx.ext.napoleon",
    "sphinx.ext.autosectionlabel",
    "sphinx.ext.intersphinx",
    "sphinx.ext.todo",
    # Pretty UX
    "sphinx_copybutton",
    "sphinx_design",
    # PHP domain for directives/roles
    "sphinxcontrib.phpdomain",
    # Optional: external links
    "sphinx.ext.extlinks",
]

# MyST options (Markdown)
myst_enable_extensions = [
    "colon_fence",
    "deflist",
    "attrs_block",
    "attrs_inline",
    "tasklist",
    "fieldlist",
    "linkify",      # auto-link plain URLs (needs linkify-it-py)
]
myst_heading_anchors = 3

# Autodoc/Napoleon (for any Python glue you might add)
autodoc_default_options = {
    "members": True,
    "undoc-members": True,
    "show-inheritance": True,
}
napoleon_google_docstring = True
napoleon_numpy_docstring  = False

# Intersphinx: only include inventories that actually exist
# (php.net is NOT a Sphinx site; use extlinks for PHP manual instead)
intersphinx_mapping = {
    "python": ("https://docs.python.org/3", None),
}

# extlinks shortcut for PHP manual
extlinks = {
    "php": ("https://www.php.net/%s", "%s"),
}

# TODOs visible in HTML
todo_include_todos = True

# --- HTML output -------------------------------------------------------------
html_theme = "sphinx_rtd_theme"
html_theme_options = {
    "collapse_navigation": False,
    "sticky_navigation": True,
    "navigation_depth": 3,
    "logo_only": False,
    "style_external_links": True,
}
templates_path   = ["_templates"]
html_static_path = ["_static"]
html_css_files   = ["theme.css"]
html_title       = f"Webrick – {version} Docs"
html_show_sourcelink = True
html_show_sphinx    = False

# Root doc in Sphinx 8 (replaces deprecated master_doc)
root_doc = "index"

# --- Pygments / PHP highlighting --------------------------------------------
from pygments.lexers.web import PhpLexer
from sphinx.highlighting import lexers

highlight_language = "php"
# Enable highlighting for PHP code not wrapped in <?php ... ?>
lexers["php"]             = PhpLexer(startinline=True)
lexers["php-annotations"] = PhpLexer(startinline=True)

# --- PDF metadata (optional) -------------------------------------------------
latex_engine = "xelatex"
latex_elements = {
    "papersize": "a4paper",
    "pointsize": "11pt",
    "preamble": "",
    "figure_align": "H",
}

# --- Context / substitutions -------------------------------------------------
html_context = {
    "display_github": False,      # you can wire this later if you want edit-on-GitHub links
    "github_user": "infocyph",
    "github_repo": "Webrick",
    "github_version": version,
    "conf_py_path": "/docs/",
}
rst_prolog = f"""
.. |current_year| replace:: {year_now}
"""
