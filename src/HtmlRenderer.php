<?php

declare(strict_types=1);

namespace G\Debug;

/**
 * Renders the bar as a self-contained HTML fragment: inline styles scoped to
 * #gdebug, a tiny inline script, no external resource.
 *
 * A collector whose summary and panel are both empty is not shown. Pages with
 * a Content-Security-Policy that forbids inline styles or scripts will not
 * display the bar correctly.
 */
final class HtmlRenderer implements RendererInterface
{
    private const STYLE = <<<'CSS'
        #gdebug{all:initial;position:fixed;left:0;right:0;bottom:0;z-index:2147483000;
        font:12px/1.4 system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--gd-fg);
        --gd-bg:#f6f6f7;--gd-fg:#1f2328;--gd-muted:#6b7280;--gd-line:#d0d4da;--gd-accent:#2563eb;
        --gd-alert:#b91c1c;--gd-alert-bg:#fde8e8;--gd-warn-bg:#fdf6dd;--gd-panel:#ffffff}
        @media (prefers-color-scheme:dark){#gdebug{--gd-bg:#1d2026;--gd-fg:#e6e8eb;--gd-muted:#9aa1ab;
        --gd-line:#363b44;--gd-accent:#7aa7ff;--gd-alert:#ff8a8a;--gd-alert-bg:#4a1f22;--gd-warn-bg:#40381d;
        --gd-panel:#15171b}}
        #gdebug *{box-sizing:border-box;margin:0;padding:0;border:0;font:inherit;color:inherit;
        background:none;text-align:left;letter-spacing:normal;text-transform:none}
        #gdebug .gd-bar{display:flex;align-items:stretch;background:var(--gd-bg);border-top:1px solid var(--gd-line);
        overflow-x:auto;white-space:nowrap}
        #gdebug button{cursor:pointer}
        #gdebug .gd-logo{font-weight:700;padding:6px 10px;color:var(--gd-accent)}
        #gdebug .gd-tab{padding:6px 10px;border-left:1px solid var(--gd-line)}
        #gdebug .gd-tab b{font-weight:600;margin-right:6px}
        #gdebug .gd-tab span{color:var(--gd-muted)}
        #gdebug .gd-tab[aria-expanded=true]{background:var(--gd-panel)}
        #gdebug .gd-tab.gd-alert,#gdebug .gd-tab.gd-alert span{color:var(--gd-alert)}
        #gdebug .gd-close{margin-left:auto;padding:6px 12px;color:var(--gd-muted)}
        #gdebug .gd-panel{background:var(--gd-panel);border-top:1px solid var(--gd-line);max-height:50vh;
        overflow:auto;padding:10px 12px}
        #gdebug .gd-panel[hidden]{display:none}
        #gdebug h2{font-size:14px;font-weight:600;margin-bottom:8px}
        #gdebug h3{font-weight:600;margin:12px 0 4px}
        #gdebug p{margin:4px 0}
        #gdebug table{border-collapse:collapse;width:100%}
        #gdebug th,#gdebug td{border-bottom:1px solid var(--gd-line);padding:3px 6px;vertical-align:top;
        word-break:break-word;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;white-space:pre-wrap}
        #gdebug th{font-weight:600;width:1%;white-space:nowrap}
        #gdebug tr.gd-alert td{background:var(--gd-alert-bg)}
        #gdebug tr.gd-warn td{background:var(--gd-warn-bg)}
        #gdebug .gd-muted{color:var(--gd-muted)}
        #gdebug pre{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;white-space:pre-wrap}
        #gdebug .gd-dump{margin-bottom:10px}
        #gdebug.gd-collapsed .gd-tab,#gdebug.gd-collapsed .gd-close,#gdebug.gd-collapsed .gd-panel{display:none}
        #gdebug.gd-collapsed{right:auto}
        CSS;

    private const SCRIPT = <<<'JS'
        (function () {
            var root = document.getElementById('gdebug');
            if (!root) { return; }
            var key = 'gdebug-collapsed';
            function store(value) { try { sessionStorage.setItem(key, value); } catch (e) {} }
            try { if (sessionStorage.getItem(key) === '1') { root.classList.add('gd-collapsed'); } } catch (e) {}
            function closeAll() {
                root.querySelectorAll('.gd-panel').forEach(function (p) { p.hidden = true; });
                root.querySelectorAll('.gd-tab[aria-controls]').forEach(function (t) {
                    t.setAttribute('aria-expanded', 'false');
                });
            }
            root.addEventListener('click', function (event) {
                var target = event.target.closest('button');
                if (!target || !root.contains(target)) { return; }
                if (target.classList.contains('gd-logo')) {
                    var collapsed = root.classList.toggle('gd-collapsed');
                    store(collapsed ? '1' : '0');
                    return;
                }
                if (target.classList.contains('gd-close')) { closeAll(); return; }
                var id = target.getAttribute('aria-controls');
                if (!id) { return; }
                var panel = document.getElementById(id);
                var open = panel && panel.hidden;
                closeAll();
                if (open) { panel.hidden = false; target.setAttribute('aria-expanded', 'true'); }
            });
        })();
        JS;

    public function render(DebugBar $bar): string
    {
        $tabs = '';
        $panels = '';

        foreach ($bar->getCollectors() as $collector) {
            $summary = $collector->getSummary();
            $panel = $collector->getPanel();

            if ($summary === '' && $panel === '') {
                continue;
            }

            $id = 'gdebug-' . $collector->getName();
            $class = $collector instanceof AlertingCollectorInterface && $collector->hasAlert()
                ? 'gd-tab gd-alert' : 'gd-tab';
            $content = '<b>' . Html::escape($collector->getLabel()) . '</b><span>' . Html::escape($summary)
                . '</span>';

            if ($panel === '') {
                $tabs .= '<span class="' . $class . '">' . $content . '</span>';
                continue;
            }

            $tabs .= '<button type="button" class="' . $class . '" aria-controls="' . Html::escape($id)
                . '" aria-expanded="false">' . $content . '</button>';
            $panels .= '<section class="gd-panel" id="' . Html::escape($id) . '" hidden><h2>'
                . Html::escape($collector->getLabel()) . '</h2>' . $panel . '</section>';
        }

        return '<div id="gdebug" role="complementary" aria-label="Debug bar">'
            . '<style>' . self::STYLE . '</style>'
            . $panels
            . '<div class="gd-bar"><button type="button" class="gd-logo" title="Show or hide the debug bar">G</button>'
            . $tabs
            . '<button type="button" class="gd-close" title="Close the panel">×</button></div>'
            . '<script>' . self::SCRIPT . '</script>'
            . '</div>';
    }
}
