{{--
    Accordion behaviour for the grouped compartment table (#167).

    Filament's collapsible groups toggle independently: it keeps an Alpine array
    of group titles (`groupVisibility`) and pushes/splices on each header click,
    so several banks can stand open at once. The compartment list is meant to
    show one locker bank at a time, so this narrows that array to whichever
    group the admin just opened.

    Because the table sets `collapsedGroupsByDefault()`, `groupVisibility` holds
    the *expanded* groups. Rather than parse titles out of the DOM, we snapshot
    the array before Filament handles the click and keep only what it added,
    which stays correct regardless of how the group title is rendered.

    Two listeners are needed. The capture-phase one snapshots the array before
    Filament's own `x-on:click` runs; the bubble-phase one applies the trim
    afterwards. Doing the trim in a microtask scheduled from the capture phase
    is too early — the microtask queue drains as soon as that listener returns,
    which is still before the event reaches the header element itself.

    This relies on `groupVisibility`, which is Filament-internal rather than
    public API; the guards below degrade to Filament's default independent
    collapsing if a future release changes it.
--}}
{{--
    Filament sizes group headers tighter than data rows (36px against 57px),
    which reads as a cramped strip rather than a row of the same table — most
    visible when every bank is collapsed and the headers are all you see. Match
    the row height so the collapsed list looks like an even stack.
--}}
<style>
    .fi-ta-group-header {
        min-height: 3.5rem;
    }
</style>

<script>
    ;(() => {
        const SELECTOR = '.fi-ta-group-header.fi-collapsible'

        let pending = null

        document.addEventListener('click', (event) => {
            pending = null

            const header = event.target.closest(SELECTOR)

            if (! header || ! window.Alpine) {
                return
            }

            let component

            try {
                component = window.Alpine.$data(header)
            } catch {
                return
            }

            if (! Array.isArray(component?.groupVisibility)) {
                return
            }

            pending = { component, before: [...component.groupVisibility] }
        }, true)

        document.addEventListener('click', () => {
            if (! pending) {
                return
            }

            const { component, before } = pending
            pending = null

            const opened = component.groupVisibility.filter((title) => ! before.includes(title))

            // Empty means the click closed a group — nothing to collapse.
            if (opened.length) {
                component.groupVisibility = opened
            }
        })
    })()
</script>
