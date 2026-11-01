// Hotspot: one line per timeline entry type, keyed by the `code` of its TimelineActivityType (and its row in
// `crm_activity_types`). `icon` is an admin-sprite id, `tone` a `timeline-item__icon--<tone>` modifier,
// `renderer` how the body is drawn ('text' = escaped, line breaks kept). #28 to #30 add a line for their type;
// TimelineTypesPinTest fails when a type has none.
export default {
    note: { icon: 'sticky-note', tone: 'grey', renderer: 'text' },
    email_logged: { icon: 'mail', tone: 'blue', renderer: 'text' },
    call: { icon: 'phone', tone: 'green', renderer: 'text' },
    meeting: { icon: 'calendar', tone: 'amber', renderer: 'text' },
    deal_stage_changed: { icon: 'square-kanban', tone: 'grey', renderer: 'text' },
};

// The fallback for a code this file does not know yet (a type added by a newer deploy than the cached bundle).
export const fallbackType = { icon: 'history', tone: 'grey', renderer: 'text' };
