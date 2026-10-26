<template>
    <div>
        <!-- Loading skeleton -->
        <div v-if="loading" class="space-y-6" aria-busy="true" aria-live="polite">
            <div class="rcmi-card p-6">
                <div class="h-4 w-32 animate-pulse rounded bg-gray-100"></div>
                <div class="mt-4 h-7 w-3/4 animate-pulse rounded bg-gray-100"></div>
                <div class="mt-4 flex gap-2">
                    <div class="h-8 w-24 animate-pulse rounded bg-gray-100"></div>
                    <div class="h-8 w-24 animate-pulse rounded bg-gray-100"></div>
                </div>
            </div>
            <div class="grid gap-6 lg:grid-cols-3">
                <div class="lg:col-span-2 space-y-6">
                    <div class="rcmi-card p-6">
                        <div class="h-4 w-24 animate-pulse rounded bg-gray-100"></div>
                        <div class="mt-3 h-4 w-full animate-pulse rounded bg-gray-100"></div>
                        <div class="mt-2 h-4 w-5/6 animate-pulse rounded bg-gray-100"></div>
                        <div class="mt-2 h-4 w-4/6 animate-pulse rounded bg-gray-100"></div>
                    </div>
                </div>
                <div class="space-y-4">
                    <div class="rcmi-card p-5">
                        <div class="h-4 w-20 animate-pulse rounded bg-gray-100"></div>
                        <div class="mt-3 h-4 w-full animate-pulse rounded bg-gray-100"></div>
                        <div class="mt-2 h-4 w-2/3 animate-pulse rounded bg-gray-100"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Error -->
        <div v-else-if="loadError" class="rcmi-card border-red-200 bg-red-50 p-8 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100 text-red-700">
                <Icon name="alert" />
            </div>
            <h2 class="mt-4 text-lg font-semibold text-red-900">{{ loadError }}</h2>
            <router-link to="/" class="rcmi-button-secondary mt-5 inline-flex px-4 py-2 text-sm">
                <Icon name="chevron-left" /> Back to tickets
            </router-link>
        </div>

        <template v-else-if="ticket">
            <!-- Breadcrumb -->
            <nav class="rcmi-breadcrumb mb-4" aria-label="Breadcrumb">
                <router-link to="/">Tickets</router-link>
                <span class="rcmi-breadcrumb-sep">/</span>
                <span class="font-semibold text-gray-700">#{{ ticket.id }}</span>
            </nav>

            <!-- Title + status header -->
            <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0 flex-1">
                    <h2 class="text-2xl font-bold leading-tight text-gray-900">{{ ticket.title }}</h2>
                    <div class="mt-2 flex flex-wrap items-center gap-3 text-sm text-gray-500">
                        <span class="font-semibold text-gray-600">{{ ticket.author_name }}</span>
                        <span aria-hidden="true">·</span>
                        <span>{{ formatDateTime(ticket.created_at) }}</span>
                    </div>
                </div>
                <div class="flex flex-shrink-0 items-center gap-2">
                    <StatusBadge :status="ticket.status" />
                </div>
            </div>

            <!-- Action buttons -->
            <div class="mb-6 flex flex-wrap items-center gap-2 border-b border-gray-200 pb-4">
                <!-- Rejected: Pending Revision — show message instead of buttons -->
                <div v-if="ticket.status === 'Rejected: Pending Revision'" class="w-full rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    <span class="inline-flex items-center gap-2">
                        <Icon name="alert" />
                        {{ isRequestor ? 'Please update ticket and resubmit.' : 'Ticket has been sent back for revision.' }}
                        <router-link v-if="canEditTicket" :to="`/ticket/${ticket.id}/edit`"
                            class="ml-2 font-semibold text-red-700 underline hover:text-red-800">
                            Edit ticket
                        </router-link>
                    </span>
                </div>

                <!-- Chain approve/reject (current-step approver only) -->
                <template v-else-if="canApproveChain">
                    <button @click="chainApprove" :disabled="approvalBusy"
                        class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">
                        <Icon name="check-circle" /> Approve Step
                    </button>
                    <!-- Reject dropdown -->
                    <div class="relative inline-block" ref="rejectDropdownRef">
                        <button @click="rejectDropdownOpen = !rejectDropdownOpen" :disabled="approvalBusy"
                            class="inline-flex items-center gap-1.5 rounded-md border border-red-300 bg-red-50 px-3.5 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-100 disabled:opacity-50">
                            <Icon name="x-circle" /> Reject
                            <Icon name="chevron-right" class="h-3.5 w-3.5 transition-transform" :class="rejectDropdownOpen ? 'rotate-90' : ''" />
                        </button>
                        <div v-if="rejectDropdownOpen" class="absolute left-0 z-30 mt-1 !w-[24rem] max-w-[calc(100vw-2rem)] rounded-lg border border-gray-200 bg-white py-1.5 shadow-xl">
                            <button type="button" @click="selectRejectType('terminal')"
                                class="flex w-full items-start gap-2.5 px-3 py-2.5 text-left text-sm hover:bg-red-50">
                                <Icon name="x-circle" class="mt-0.5 h-4 w-4 flex-shrink-0 text-red-600" />
                                <div>
                                    <div class="font-semibold text-gray-900">Close the ticket</div>
                                    <div class="text-xs text-gray-500">Terminal reject — ticket is closed as Rejected.</div>
                                </div>
                            </button>
                            <button type="button" @click="selectRejectType('restart')"
                                class="flex w-full items-start gap-2.5 px-3 py-2.5 text-left text-sm hover:bg-red-50">
                                <Icon name="reply" class="mt-0.5 h-4 w-4 flex-shrink-0 text-red-600" />
                                <div>
                                    <div class="font-semibold text-gray-900">Send back to requestor</div>
                                    <div class="text-xs text-gray-500">Restart approval from step 1 after requestor revises.</div>
                                </div>
                            </button>
                            <button v-if="hasPreviousApprovalStep" type="button" @click="selectRejectType('back_one')"
                                class="flex w-full items-start gap-2.5 px-3 py-2.5 text-left text-sm hover:bg-red-50">
                                <Icon name="chevron-left" class="mt-0.5 h-4 w-4 flex-shrink-0 text-red-600" />
                                <div>
                                    <div class="font-semibold text-gray-900">Send back to previous approver</div>
                                    <div class="text-xs text-gray-500">Go back one step in the approval chain.</div>
                                </div>
                            </button>
                        </div>
                    </div>
                    <!-- Reject comment form (shown after selecting a reject type) -->
                    <div v-if="chainRejectOpen" class="mt-2 w-full rounded-lg border border-red-200 bg-red-50/70 p-4 sm:max-w-2xl">
                        <div class="flex items-start gap-3">
                            <span class="mt-0.5 flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-red-100 text-red-700">
                                <Icon name="alert" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <h3 class="text-sm font-semibold text-red-900">{{ rejectTypeLabel }}</h3>
                                <p class="mt-1 text-xs leading-relaxed text-red-800">Explain what needs to change. This reason will be saved in the approval timeline.</p>
                                <label for="chain-reject-reason" class="sr-only">Reason for rejection</label>
                                <textarea id="chain-reject-reason" v-model="chainRejectComment" rows="3"
                                    placeholder="Enter a clear reason for rejection…"
                                    class="rcmi-input mt-3 resize-y bg-white" aria-label="Reason for rejection"></textarea>
                                <div class="mt-3 flex flex-wrap items-center gap-2">
                                    <button @click="chainReject" :disabled="!chainRejectComment.trim() || approvalBusy"
                                        class="rcmi-button-danger inline-flex items-center gap-1.5 px-3.5 py-2 text-sm disabled:opacity-50">
                                        <Icon name="x-circle" /> {{ approvalBusy ? 'Submitting…' : 'Submit rejection' }}
                                    </button>
                                    <button @click="cancelReject"
                                        class="rcmi-button-ghost px-3 py-2 text-sm">Cancel</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- Legacy status changes (only when NO chain is active) -->
                <template v-if="!hasActiveChain">
                    <template v-if="canChangeStatus('Approved') && ['Received', 'Pending Approval'].includes(ticket.status)">
                        <button @click="changeStatus('Approved')" :disabled="statusChanging"
                            class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">
                            <Icon name="check-circle" /> Approve
                        </button>
                    </template>
                    <template v-if="canChangeStatus('Rejected')">
                        <button @click="rejectOpen = !rejectOpen" :disabled="statusChanging"
                            class="inline-flex items-center gap-1.5 rounded-md border border-red-300 bg-red-50 px-3.5 py-2 text-sm font-semibold text-red-700 hover:bg-red-100 disabled:opacity-50">
                            <Icon name="x-circle" /> Reject
                        </button>
                    </template>
                </template>
                <div v-if="canChangeStatus('In Progress') && ticket.status === 'Approved'" class="mt-2 flex w-full items-center gap-2 border-t border-gray-200 pt-3">
                    <span class="mr-1 text-xs text-gray-500">Ticket actions</span>
                    <button @click="changeStatus('In Progress')" :disabled="statusChanging"
                        class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:opacity-50">
                        <Icon name="arrow-right" /> Start work
                    </button>
                </div>
                <div v-if="canChangeStatus('Completed') && ticket.status === 'In Progress'" class="mt-2 flex w-full items-center gap-2 border-t border-gray-200 pt-3">
                    <span class="mr-1 text-xs text-gray-500">Ticket actions</span>
                    <button @click="changeStatus('Completed')" :disabled="statusChanging"
                        class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-50">
                        <Icon name="check-badge" /> Complete ticket
                    </button>
                </div>

                <!-- Legacy reject reason input -->
                <div v-if="!hasActiveChain && rejectOpen" class="flex w-full items-center gap-2 sm:w-auto">
                    <input v-model="rejectMessage" type="text" placeholder="Reason for rejection…"
                        class="rcmi-input flex-1 sm:w-64" aria-label="Reason for rejection" />
                    <button @click="changeStatus('Rejected')" :disabled="!rejectMessage.trim() || statusChanging"
                        class="rcmi-button-primary px-3.5 py-2 text-sm disabled:opacity-50">
                        {{ statusChanging ? '…' : 'Confirm' }}
                    </button>
                    <button @click="rejectOpen = false; rejectMessage = ''"
                        class="rcmi-button-ghost px-3 py-2 text-sm">Cancel</button>
                </div>

                <!-- Edit / Delete / Copy link -->
                <div class="ml-auto flex items-center gap-2">
                    <button @click="copyLink" class="rcmi-button-ghost inline-flex items-center gap-1.5 px-3 py-2 text-sm">
                        <Icon name="copy" /> Copy link
                    </button>
                    <router-link v-if="canEditTicket" :to="`/ticket/${ticket.id}/edit`"
                        class="rcmi-button-secondary inline-flex items-center gap-1.5 px-3.5 py-2 text-sm">
                        <Icon name="edit" /> Edit
                    </router-link>
                    <button v-if="canDeleteTicket && !isCompletedTicket && !isArchivedTicket" @click="confirmDelete = true"
                        class="rcmi-button-danger inline-flex items-center gap-1.5 px-3.5 py-2 text-sm">
                        <Icon name="trash" /> Delete
                    </button>
                    <button v-if="canDeleteTicket && isCompletedTicket && !isArchivedTicket" @click="sendToHeaven"
                        :disabled="archiving"
                        class="inline-flex items-center gap-1.5 rounded-md border border-purple-300 bg-purple-50 px-3.5 py-2 text-sm font-semibold text-purple-700 transition hover:bg-purple-100 disabled:opacity-50">
                        <Icon name="archive" /> {{ archiving ? 'Sending…' : 'Send to Heaven' }}
                    </button>
                    <button v-if="canDeleteTicket && isArchivedTicket" @click="resurrectTicket"
                        :disabled="archiving"
                        class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-50">
                        <Icon name="check-circle" /> {{ archiving ? 'Resurrecting…' : 'Resurrect' }}
                    </button>
                </div>
            </div>

            <!-- Two-column layout -->
            <div class="rcmi-ticket-detail-layout">
                <!-- Main column -->
                <div class="space-y-6">
                    <!-- Ticket Details (custom fields) -->
                    <section v-if="displayFormFields.length > 0 && hasCustomAnswers" class="rcmi-card p-6">
                        <h3 class="rcmi-section-label mb-4">Ticket Details</h3>
                        <DynamicForm :fields="displayFormFields" :model-value="ticket.form_answers || {}" readonly />
                    </section>

                    <!-- Attachments -->
                    <section v-if="ticket.attachments && ticket.attachments.length > 0" class="rcmi-card p-6">
                        <h3 class="rcmi-section-label mb-4">Attachments</h3>
                        <ul class="space-y-2">
                            <li v-for="a in ticket.attachments" :key="a.id" class="flex items-center gap-3 rounded-md border border-gray-100 px-3 py-2 text-sm transition hover:border-gray-200 hover:bg-gray-50">
                                <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500">
                                    <Icon :name="fileIcon(a.mime_type)" />
                                </span>
                                <a :href="downloadUrl(a.id)" class="flex-1 truncate font-medium text-gray-800 hover:text-red-700 hover:underline">
                                    {{ a.original_name }}
                                </a>
                                <span class="flex-shrink-0 text-xs text-gray-500">{{ formatSize(a.size) }}</span>
                            </li>
                        </ul>
                    </section>

                    <!-- Comments -->
                    <section class="rcmi-card p-6">
                        <CommentThread
                            :ticket-id="ticket.id"
                            :current-user-id="meta.current_user.id"
                            :can-manage="meta.caps.manage"
                        />
                    </section>
                </div>

                <!-- Sidebar -->
                <div class="space-y-4">
                    <!-- Timeline -->
                    <section v-if="ticket.approval_history && ticket.approval_history.length > 0" class="rcmi-card p-5">
                        <h3 class="rcmi-section-label mb-4">Timeline</h3>
                        <ApprovalTimeline :steps="ticket.approval_history" :chain="ticket.approval_chain" :status-history="ticket.status_history || []" :assignees="ticket.assignees || []" :start-at="ticket.created_at" />
                    </section>

                    <!-- Customer feedback — managers/admins only -->
                    <section v-if="showManagerSurveyCard" class="rcmi-card p-5">
                        <div class="mb-4 flex items-center justify-between gap-2">
                            <h3 class="rcmi-section-label">Customer feedback</h3>
                            <button v-if="!surveySubmitted" @click="resendSurvey"
                                :disabled="!canResendSurvey"
                                class="rcmi-button-secondary inline-flex items-center gap-1 px-2.5 py-1.5 text-xs disabled:cursor-not-allowed disabled:opacity-50"
                                :title="surveyEligible ? 'Send the survey email again' : 'Survey is not eligible for this ticket'">
                                <Icon name="mail" /> {{ surveySending ? 'Sending…' : 'Resend survey' }}
                            </button>
                        </div>

                        <!-- Submitted answers -->
                        <template v-if="surveySubmitted">
                            <p class="mb-3 text-xs text-gray-500">
                                Submitted {{ formatDateTime(surveyInfo.response.submitted_at) }}<span v-if="surveyInfo.response.respondent_email"> by {{ surveyInfo.response.respondent_email }}</span>
                            </p>
                            <dl class="space-y-3">
                                <div v-for="a in surveyAnswers" :key="a.field_key">
                                    <dt class="text-xs font-semibold text-gray-500">{{ a.label }}</dt>
                                    <dd v-if="a.type === 'rating'" class="mt-0.5">
                                        <span class="inline-flex items-center gap-0.5" :aria-label="`${a.value} of ${surveyRatingMax(a.field_key)} stars`">
                                            <Icon v-for="n in surveyRatingMax(a.field_key)" :key="n" name="star"
                                                class="h-4 w-4" :class="n <= Number(a.value) ? 'text-amber-400' : 'text-gray-200'" />
                                            <span class="ml-1 text-xs text-gray-500">{{ a.value }}/{{ surveyRatingMax(a.field_key) }}</span>
                                        </span>
                                    </dd>
                                    <dd v-else class="mt-0.5 whitespace-pre-wrap text-sm text-gray-700">{{ surveyAnswerText(a) }}</dd>
                                </div>
                            </dl>
                        </template>
                        <p v-else class="text-sm text-gray-500">
                            {{ surveyEligible ? 'No response yet — the survey invite has been sent.' : 'No response yet.' }}
                        </p>
                        <p v-if="!surveySubmitted && !surveyEligible && surveyInfo.eligibility?.reasons?.length"
                            class="mt-1 text-xs text-gray-400">
                            Survey not eligible: {{ surveyInfo.eligibility.reasons.join(', ') }}
                        </p>

                        <!-- Email log -->
                        <div v-if="surveyEmails.length" class="mt-4 border-t border-gray-100 pt-3">
                            <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Survey emails</h4>
                            <ul class="space-y-1.5 text-xs text-gray-600">
                                <li v-for="e in surveyEmails" :key="e.id" class="flex items-start gap-1.5">
                                    <Icon :name="e.success ? 'check-circle' : 'x-circle'"
                                        class="mt-0.5 h-3.5 w-3.5 flex-shrink-0" :class="e.success ? 'text-emerald-600' : 'text-red-600'" />
                                    <span class="min-w-0">
                                        <span class="font-medium text-gray-700">{{ surveyEmailKindLabel(e.kind) }}</span>
                                        → <span class="break-all">{{ e.recipient }}</span>
                                        <span class="text-gray-400"> · {{ formatDateTime(e.sent_at) }}<template v-if="e.sent_by_name"> · by {{ e.sent_by_name }}</template></span>
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </section>

                    <!-- Requester survey link — requester only, Completed tickets -->
                    <section v-else-if="showRequesterSurvey" class="rcmi-card p-5">
                        <h3 class="rcmi-section-label mb-3">Your feedback</h3>
                        <router-link :to="`/survey/${ticket.id}`"
                            class="rcmi-button-primary inline-flex items-center gap-1.5 px-3.5 py-2 text-sm">
                            <Icon name="star" /> {{ requesterSurveyState === 'submitted' ? 'View your feedback' : 'Rate this ticket' }}
                        </router-link>
                    </section>

                    <!-- Details card -->
                    <section class="rcmi-card p-5">
                        <div class="mb-4 flex items-center justify-between">
                            <h3 class="rcmi-section-label">Details</h3>
                            <button v-if="canEditAssignees && !detailsEditing" type="button" @click.stop="toggleDetailsEditing"
                                aria-label="Edit details"
                                class="rcmi-button-secondary inline-flex items-center gap-1.5 px-3 py-1.5 text-xs">
                                <Icon name="edit" class="h-3.5 w-3.5" />Edit
                            </button>
                        </div>
                        <dl class="space-y-4">
                            <div>
                                <dt class="text-xs font-semibold text-gray-500">Due Date</dt>
                                <!-- Inline editor for managers (staged until Save) -->
                                <dd v-if="isManager && detailsEditing" class="mt-1">
                                    <input type="date" v-model="draftDueDate"
                                        :disabled="detailsSaving"
                                        class="rcmi-input max-w-[10rem] px-2 py-1 text-sm" aria-label="Due date" />
                                </dd>
                                <dd v-else class="mt-1 text-sm" :class="ticket.due_date ? 'text-gray-700' : 'text-gray-400'">
                                    {{ ticket.due_date ? formatDate(ticket.due_date) : '—' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold text-gray-500">Requestor</dt>
                                <dd class="mt-1 flex items-center gap-2 text-sm text-gray-700">
                                    <button type="button" @click="requestorProfileOpen = true"
                                        :aria-label="`View requestor profile (${ticket.author_name || 'Unknown'})`"
                                        class="rounded-full transition hover:ring-2 hover:ring-red-300 focus:outline-none focus:ring-2 focus:ring-red-400">
                                        <img v-if="ticket.author_photo" :src="ticket.author_photo" :alt="ticket.author_name" class="h-7 w-7 rounded-full object-cover" />
                                        <span v-else class="rcmi-avatar h-7 w-7 text-xs">{{ initials(ticket.author_name) }}</span>
                                    </button>
                                    <router-link :to="{ name: 'ticket-list', query: { author: ticket.author_id } }"
                                        class="rounded transition hover:text-red-700 hover:underline focus:outline-none focus:ring-2 focus:ring-red-400"
                                        :aria-label="`Tickets submitted by ${ticket.author_name || 'Unknown'}`">
                                        {{ ticket.author_name || 'Unknown' }}
                                    </router-link>
                                </dd>
                                <dd v-if="ticket.author_email" class="mt-1 pl-9 text-xs text-gray-500">
                                    <a :href="`mailto:${ticket.author_email}`" class="hover:text-red-700 hover:underline">{{ ticket.author_email }}</a>
                                </dd>
                                <dd v-if="ticket.author_job_title || ticket.author_department" class="mt-0.5 pl-9 text-xs text-gray-500">
                                    {{ [ticket.author_job_title, ticket.author_department].filter(Boolean).join(' · ') }}
                                </dd>
                            </div>
                            <div class="relative" ref="assigneeDropdownRef">
                                <dt class="mb-1 text-xs font-semibold text-gray-500">
                                    Assignee{{ shownAssignees.length > 1 ? 's' : '' }}
                                </dt>

                                <!-- Interactive Dropdown trigger — only while editing -->
                                <div v-if="canEditAssignees && detailsEditing" class="space-y-1.5">
                                    <button type="button" @click="toggleAssigneePicker"
                                        :aria-expanded="assigneePickerOpen" aria-haspopup="listbox"
                                        class="group flex w-full items-center justify-between rounded-md border border-gray-200 bg-white p-2 text-left text-sm transition hover:border-red-300 hover:bg-gray-50/70 focus:border-red-500 focus:outline-none focus:ring-2 focus:ring-red-100">
                                        <div class="min-w-0 flex-1">
                                            <template v-if="draftAssignees.length > 0">
                                                <div class="flex flex-col gap-1.5">
                                                    <div v-for="a in draftAssignees" :key="a.id" class="flex items-center gap-2">
                                                        <img v-if="a.photo" :src="a.photo" :alt="a.display_name" class="h-6 w-6 flex-shrink-0 rounded-full object-cover" />
                                                        <span v-else class="rcmi-avatar h-6 w-6 text-xs flex-shrink-0">{{ initials(a.display_name) }}</span>
                                                        <span class="truncate font-medium text-gray-800">{{ a.display_name }}</span>
                                                    </div>
                                                </div>
                                            </template>
                                            <span v-else class="flex items-center gap-1.5 text-xs font-medium text-gray-400 group-hover:text-red-700">
                                                <Icon name="plus" class="h-3.5 w-3.5" /> Assign…
                                            </span>
                                        </div>
                                        <Icon name="chevron-right" class="ml-2 h-4 w-4 flex-shrink-0 text-gray-400 transition-transform duration-150 group-hover:text-red-700"
                                            :class="assigneePickerOpen ? 'rotate-90' : ''" />
                                    </button>

                                    <!-- Dropdown Popover -->
                                    <div v-if="assigneePickerOpen" class="absolute left-0 right-0 z-30 mt-1 rounded-lg border border-gray-200 bg-white p-2.5 shadow-xl">
                                        <div class="mb-2 flex items-center justify-between border-b border-gray-100 pb-2">
                                            <span class="text-xs font-bold text-gray-700">Select Assignees</span>
                                            <button type="button" @click="assigneePickerOpen = false" class="rounded p-0.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                                                <Icon name="x" class="h-3.5 w-3.5" />
                                            </button>
                                        </div>
                                        <label class="sr-only" for="assignee-search">Search assignees</label>
                                        <div class="relative mb-2">
                                            <Icon name="search" class="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-gray-400" />
                                            <input id="assignee-search" ref="assigneeSearchInput" v-model="assigneeSearch"
                                                @keydown.esc="assigneePickerOpen = false" type="search"
                                                placeholder="Search people…" class="rcmi-input w-full pl-8 py-1.5 text-xs" />
                                        </div>
                                        <div v-if="filteredAssigneeUsers.length" class="max-h-52 overflow-y-auto space-y-0.5" role="listbox" aria-label="Assignable users" aria-multiselectable="true">
                                            <button v-for="u in filteredAssigneeUsers" :key="u.id" type="button"
                                                @click="toggleAssigneeDraft(u.id)" :disabled="detailsSaving"
                                                role="option" :aria-selected="isDraftAssignee(u.id)"
                                                class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-xs transition hover:bg-red-50 disabled:opacity-50">
                                                <span class="flex h-4 w-4 flex-shrink-0 items-center justify-center rounded border transition"
                                                    :class="isDraftAssignee(u.id) ? 'border-red-600 bg-red-600 text-white' : 'border-gray-300 bg-white'">
                                                    <Icon v-if="isDraftAssignee(u.id)" name="check" class="h-3 w-3" />
                                                </span>
                                                <img v-if="u.photo" :src="u.photo" :alt="u.display_name" class="h-5 w-5 flex-shrink-0 rounded-full object-cover" />
                                                <span v-else class="rcmi-avatar h-5 w-5 text-[10px] flex-shrink-0">{{ initials(u.display_name) }}</span>
                                                <span class="min-w-0 flex-1 truncate">
                                                    <span class="block truncate font-medium text-gray-800">{{ u.display_name }}</span>
                                                    <span v-if="u.user_email" class="block truncate text-[11px] text-gray-400">{{ u.user_email }}</span>
                                                </span>
                                            </button>
                                        </div>
                                        <p v-else class="py-4 text-center text-xs text-gray-400">No matching users</p>
                                    </div>
                                </div>

                                <!-- Read-only view (no permission, or not editing) -->
                                <template v-else>
                                    <template v-if="shownAssignees.length > 0">
                                        <div v-for="a in shownAssignees" :key="a.id">
                                            <dd class="mt-1 flex items-center gap-2 text-sm text-gray-700">
                                                <button type="button" @click="profileAssignee = a"
                                                    :aria-label="`View assignee profile (${a.display_name})`"
                                                    class="flex-shrink-0 rounded-full transition hover:ring-2 hover:ring-red-300 focus:outline-none focus:ring-2 focus:ring-red-400">
                                                    <img v-if="a.photo" :src="a.photo" :alt="a.display_name" class="h-7 w-7 rounded-full object-cover" />
                                                    <span v-else class="rcmi-avatar h-7 w-7 text-xs">{{ initials(a.display_name) }}</span>
                                                </button>
                                                <router-link :to="{ name: 'ticket-list', query: { assignee: a.id } }"
                                                    class="rounded transition hover:text-red-700 hover:underline focus:outline-none focus:ring-2 focus:ring-red-400"
                                                    :aria-label="`Tickets assigned to ${a.display_name}`">
                                                    {{ a.display_name }}
                                                </router-link>
                                            </dd>
                                            <dd v-if="a.user_email" class="mt-1 pl-9 text-xs text-gray-500">
                                                <a :href="`mailto:${a.user_email}`" class="hover:text-red-700 hover:underline">{{ a.user_email }}</a>
                                            </dd>
                                            <dd v-if="a.job_title || a.department" class="mt-0.5 pl-9 text-xs text-gray-500">
                                                {{ [a.job_title, a.department].filter(Boolean).join(' · ') }}
                                            </dd>
                                        </div>
                                    </template>
                                    <dd v-else class="mt-1 text-sm text-gray-400">Unassigned</dd>
                                </template>
                            </div>
                            <div v-if="ticket.updated_by_name">
                                <dt class="text-xs font-semibold text-gray-500">Last Updated By</dt>
                                <dd class="mt-1 text-sm text-gray-700">{{ ticket.updated_by_name }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold text-gray-500">Created</dt>
                                <dd class="mt-1 text-sm text-gray-700">{{ formatDateTime(ticket.created_at) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold text-gray-500">Updated</dt>
                                <dd class="mt-1 text-sm text-gray-700">{{ formatDateTime(ticket.updated_at) }}</dd>
                            </div>
                        </dl>

                        <!-- Edit-mode actions — the only way out of editing.
                             Save is enabled once due date or assignees differ
                             from the saved ticket; saving fires the
                             server-side notification emails. -->
                        <div v-if="detailsEditing" class="mt-4 flex items-center gap-2 border-t border-gray-100 pt-3">
                            <button @click="saveDetails" :disabled="detailsSaving || !detailsDirty"
                                class="rcmi-button-primary inline-flex items-center gap-1.5 px-3.5 py-1.5 text-xs disabled:opacity-50">
                                <Icon name="save" /> {{ detailsSaving ? 'Saving…' : 'Save changes' }}
                            </button>
                            <button @click="syncDetailsDraft" :disabled="detailsSaving"
                                class="rcmi-button-ghost px-3 py-1.5 text-xs disabled:opacity-50">Cancel</button>
                            <span v-if="detailsDirty" class="ml-auto text-[11px] font-medium text-amber-600">Unsaved changes</span>
                        </div>
                    </section>

                    <!-- Linked tickets -->
                    <section v-if="linkedTickets.length > 0 || canEditAssignees" class="rcmi-card p-5">
                        <h3 class="rcmi-section-label mb-4">Linked Tickets</h3>
                        <ul v-if="linkedTickets.length" class="space-y-1.5">
                            <li v-for="lt in linkedTickets" :key="lt.id"
                                class="flex items-center gap-2 rounded-md border border-gray-100 px-3 py-2 text-sm transition hover:border-red-200 hover:bg-gray-50">
                                <router-link :to="`/ticket/${lt.id}`"
                                    class="min-w-0 flex-1 truncate font-medium text-gray-800 hover:text-red-700 hover:underline">
                                    #{{ lt.id }} — {{ lt.title }}
                                </router-link>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-gray-400">No linked tickets yet.</p>
                        <button v-if="canEditAssignees" type="button" @click="openLinkPicker"
                            class="rcmi-button-secondary mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 text-xs">
                            <Icon name="link" class="h-3.5 w-3.5" /> {{ linkedTickets.length ? 'Manage links' : 'Link a ticket' }}
                        </button>
                    </section>

                    <!-- Tags card -->
                    <section v-if="ticket.tags && ticket.tags.length > 0" class="rcmi-card p-5">
                        <h3 class="rcmi-section-label mb-4">Tags</h3>
                        <div class="flex flex-wrap gap-1.5">
                            <span v-for="t in ticket.tags" :key="t.id" class="rcmi-tag-pill">
                                {{ t.name }}
                            </span>
                        </div>
                    </section>
                </div>
            </div>

            <!-- Requestor profile modal -->
            <Modal v-if="requestorProfileOpen" @close="requestorProfileOpen = false" title="Requestor" titleClass="rcmi-modal-title-sans">
                <div class="flex items-center gap-3 border-b border-gray-100 pb-4">
                    <img v-if="ticket.author_photo" :src="ticket.author_photo" :alt="ticket.author_name" class="h-14 w-14 rounded-full object-cover" />
                    <span v-else class="rcmi-avatar h-14 w-14 text-xl">{{ initials(ticket.author_name) }}</span>
                    <div class="min-w-0">
                        <div class="truncate text-base font-semibold text-gray-900">{{ ticket.author_name || 'Unknown' }}</div>
                        <div v-if="ticket.author_job_title" class="truncate text-sm text-gray-600">{{ ticket.author_job_title }}</div>
                        <div v-if="ticket.author_department" class="truncate text-xs text-gray-500">{{ ticket.author_department }}</div>
                    </div>
                </div>
                <dl class="mt-4 space-y-3 text-sm">
                    <div v-if="ticket.author_email" class="flex items-baseline gap-3">
                        <dt class="w-28 flex-shrink-0 text-xs font-semibold uppercase tracking-wide text-gray-500">Email</dt>
                        <dd><a :href="`mailto:${ticket.author_email}`" class="text-red-700 hover:underline">{{ ticket.author_email }}</a></dd>
                    </div>
                    <div v-if="ticket.author_phone" class="flex items-baseline gap-3">
                        <dt class="w-28 flex-shrink-0 text-xs font-semibold uppercase tracking-wide text-gray-500">Phone</dt>
                        <dd class="text-gray-700">{{ ticket.author_phone }}</dd>
                    </div>
                    <div v-if="ticket.author_employee_id" class="flex items-baseline gap-3">
                        <dt class="w-28 flex-shrink-0 text-xs font-semibold uppercase tracking-wide text-gray-500">Employee ID</dt>
                        <dd class="text-gray-700">{{ ticket.author_employee_id }}</dd>
                    </div>
                </dl>
            </Modal>

            <!-- Assignee profile modal -->
            <Modal v-if="profileAssignee" @close="profileAssignee = null" title="Assignee" titleClass="rcmi-modal-title-sans">
                <div class="flex items-center gap-3 border-b border-gray-100 pb-4">
                    <img v-if="profileAssignee.photo" :src="profileAssignee.photo" :alt="profileAssignee.display_name" class="h-14 w-14 rounded-full object-cover" />
                    <span v-else class="rcmi-avatar h-14 w-14 text-xl">{{ initials(profileAssignee.display_name) }}</span>
                    <div class="min-w-0">
                        <div class="truncate text-base font-semibold text-gray-900">{{ profileAssignee.display_name }}</div>
                        <div v-if="profileAssignee.job_title" class="truncate text-sm text-gray-600">{{ profileAssignee.job_title }}</div>
                        <div v-if="profileAssignee.department" class="truncate text-xs text-gray-500">{{ profileAssignee.department }}</div>
                    </div>
                </div>
                <dl class="mt-4 space-y-3 text-sm">
                    <div v-if="profileAssignee.user_email" class="flex items-baseline gap-3">
                        <dt class="w-28 flex-shrink-0 text-xs font-semibold uppercase tracking-wide text-gray-500">Email</dt>
                        <dd><a :href="`mailto:${profileAssignee.user_email}`" class="text-red-700 hover:underline">{{ profileAssignee.user_email }}</a></dd>
                    </div>
                    <div v-if="profileAssignee.phone" class="flex items-baseline gap-3">
                        <dt class="w-28 flex-shrink-0 text-xs font-semibold uppercase tracking-wide text-gray-500">Phone</dt>
                        <dd class="text-gray-700">{{ profileAssignee.phone }}</dd>
                    </div>
                    <div v-if="profileAssignee.employee_id" class="flex items-baseline gap-3">
                        <dt class="w-28 flex-shrink-0 text-xs font-semibold uppercase tracking-wide text-gray-500">Employee ID</dt>
                        <dd class="text-gray-700">{{ profileAssignee.employee_id }}</dd>
                    </div>
                </dl>
            </Modal>

            <!-- Link tickets modal -->
            <Modal v-if="linkPickerOpen" @close="linkPickerOpen = false" title="Link tickets" :wide="true" titleClass="rcmi-modal-title-sans">
                <!-- Status filter -->
                <div class="mb-3 flex flex-wrap gap-1.5" role="group" aria-label="Filter by status">
                    <button type="button" @click="linkStatus = ''; loadLinkOptions()"
                        class="rounded-full border px-2.5 py-1 text-xs font-semibold transition"
                        :class="linkStatus === '' ? 'border-red-600 bg-red-50 text-red-700' : 'border-gray-200 text-gray-500 hover:border-gray-300 hover:text-gray-700'">
                        All
                    </button>
                    <button v-for="s in meta.statuses" :key="s" type="button" @click="linkStatus = s; loadLinkOptions()"
                        class="rounded-full border px-2.5 py-1 text-xs font-semibold transition"
                        :class="linkStatus === s ? 'border-red-600 bg-red-50 text-red-700' : 'border-gray-200 text-gray-500 hover:border-gray-300 hover:text-gray-700'">
                        {{ s }}
                    </button>
                </div>
                <!-- Search -->
                <div class="relative mb-3">
                    <Icon name="search" class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400" />
                    <input v-model="linkSearch" @input="queueLinkSearch" type="search"
                        placeholder="Search tickets by title or #…" aria-label="Search tickets"
                        class="rcmi-input w-full py-2 pl-8 text-sm" />
                </div>
                <!-- Results -->
                <div class="max-h-72 overflow-y-auto rounded-lg border border-gray-100">
                    <p v-if="linkLoading" class="py-8 text-center text-xs text-gray-400">Loading…</p>
                    <p v-else-if="!linkResults.length" class="py-8 text-center text-xs text-gray-400">No tickets match</p>
                    <label v-else v-for="t in linkResults" :key="t.id"
                        class="flex cursor-pointer items-center gap-2.5 border-b border-gray-50 px-3 py-2.5 text-sm transition last:border-0 hover:bg-red-50/50">
                        <input type="checkbox" :checked="linkSelection.has(t.id)" @change="toggleLinkSelection(t.id)"
                            class="h-4 w-4 rounded border-gray-300 text-red-600 focus:ring-red-500" />
                        <span class="flex-shrink-0 text-xs text-gray-400">#{{ t.id }}</span>
                        <span class="min-w-0 flex-1 truncate font-medium text-gray-800">{{ t.title }}</span>
                        <StatusBadge :status="t.status" class="flex-shrink-0" />
                    </label>
                </div>
                <template #footer>
                    <button @click="saveLinks" :disabled="linksSaving"
                        class="rcmi-button-primary px-4 py-2 text-sm disabled:opacity-50">
                        {{ linksSaving ? 'Saving…' : 'Save links' }}
                    </button>
                    <button @click="linkPickerOpen = false" :disabled="linksSaving"
                        class="rcmi-button-secondary px-4 py-2 text-sm disabled:opacity-50">Cancel</button>
                </template>
            </Modal>

            <!-- Delete confirmation modal -->
            <Modal v-if="confirmDelete" @close="confirmDelete = false" title="Delete ticket">
                <p class="text-sm text-gray-700">
                    Delete <strong class="text-gray-900">ticket #{{ ticket.id }}</strong> permanently?
                    This will remove all comments and attachments.
                </p>
                <template #footer>
                    <button @click="deleteTicket" :disabled="deleting"
                        class="rcmi-button-primary px-4 py-2 text-sm disabled:opacity-50">
                        {{ deleting ? 'Deleting…' : 'Yes, delete' }}
                    </button>
                    <button @click="confirmDelete = false"
                        class="rcmi-button-secondary px-4 py-2 text-sm">Cancel</button>
                </template>
            </Modal>
        </template>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api.js';
import StatusBadge from '../components/StatusBadge.vue';
import CommentThread from '../components/CommentThread.vue';
import Modal from '../components/Modal.vue';
import Icon from '../components/Icon.vue';
import DynamicForm from '../components/DynamicForm.vue';
import ApprovalTimeline from '../components/ApprovalTimeline.vue';
import { useToast } from '../composables/useToast.js';
import { useConfetti } from '../composables/useConfetti.js';

const props = defineProps({ id: { type: String, required: true } });
const router = useRouter();
const toast = useToast();
const confetti = useConfetti();

const ticket = ref(null);
const meta = reactive({ current_user: {}, caps: {}, form_fields: [] });
const loading = ref(true);
const loadError = ref('');
const statusChanging = ref(false);
const deleting = ref(false);
const confirmDelete = ref(false);
const requestorProfileOpen = ref(false);
const rejectOpen = ref(false);
const rejectMessage = ref('');
const chainRejectOpen = ref(false);
const chainRejectComment = ref('');
const chainRejectType = ref('restart'); // 'terminal' | 'restart' | 'back_one'
const rejectDropdownOpen = ref(false);
const rejectDropdownRef = ref(null);
const approvalBusy = ref(false);

const isManager = computed(() => meta.caps.manage === true);
const isRequestor = computed(() => ticket.value && meta.current_user.id === ticket.value.author_id);
const isAssignee = computed(() => ticket.value && ticket.value.assignee_ids && ticket.value.assignee_ids.includes(meta.current_user.id));

const hasActiveChain = computed(() => {
    return ticket.value && ticket.value.approval_history && ticket.value.approval_history.length > 0;
});

const canApproveChain = computed(() => {
    if (!ticket.value || !ticket.value.current_approval_step) return false;
    if (ticket.value.status !== 'Pending Approval') return false;
    // Only the actual approver of the current step should see the buttons
    return ticket.value.can_approve_current_step === true;
});

// Whether the current pending step has a previous step in the chain (for back_one reject)
const hasPreviousApprovalStep = computed(() => {
    if (!ticket.value || !ticket.value.current_approval_step) return false;
    const currentSortOrder = ticket.value.current_approval_step.sort_order;
    if (!ticket.value.approval_history) return false;
    return ticket.value.approval_history.some(s => s.sort_order < currentSortOrder);
});

const rejectTypeLabel = computed(() => {
    switch (chainRejectType.value) {
        case 'terminal': return 'Reject — Close the ticket';
        case 'back_one': return 'Reject — Send back to previous approver';
        default: return 'Reject — Send back to requestor';
    }
});

const hasCustomAnswers = computed(() => {
    if (!ticket.value || !ticket.value.form_answers) return false;
    return Object.keys(ticket.value.form_answers).length > 0;
});

// Read-only custom fields: exclude the reserved "Due Date" field — its
// value lives in ticket.due_date and is shown in the Details card.
const displayFormFields = computed(() => {
    return (meta.form_fields || []).filter(f => !f.reserved);
});

const canEditTicket = computed(() => {
    if (!ticket.value) return false;
    if (isManager.value) return true;
    // Requestor can edit when status is Received or rejected (pending resubmission)
    return isRequestor.value && ['Received', 'Rejected: Pending Revision'].includes(ticket.value.status);
});

const canDeleteTicket = computed(() => isManager.value);
const isCompletedTicket = computed(() => ticket.value && ticket.value.status === 'Completed');
const isArchivedTicket = computed(() => ticket.value && ticket.value.archived === true);
const archiving = ref(false);

// ── Satisfaction survey ────────────────────────────────────
// Managers get the full feedback card (answers, email log, resend).
// The requester gets a "Rate this ticket" / "View your feedback" link.
// Assignees and everyone else see nothing.
const surveyInfo = ref(null);      // manager: {eligibility, survey_url, response, emails}
const surveyFields = ref([]);
const requesterSurvey = ref(null); // requester: {state, response, …}
const surveySending = ref(false);
const surveyLoaded = ref(false);

const showManagerSurveyCard = computed(() =>
    isManager.value && surveyLoaded.value && surveyInfo.value
    && (isCompletedTicket.value || surveyInfo.value.response || (surveyInfo.value.emails || []).length));

const requesterSurveyState = computed(() => requesterSurvey.value?.state || '');
const showRequesterSurvey = computed(() =>
    !isManager.value && isRequestor.value && isCompletedTicket.value
    && ['open', 'submitted'].includes(requesterSurveyState.value));

const surveyAnswers = computed(() => surveyInfo.value?.response?.answers || []);
const surveyEmails = computed(() => surveyInfo.value?.emails || []);
const surveyEligible = computed(() => surveyInfo.value?.eligibility?.eligible === true);
const surveySubmitted = computed(() => Boolean(surveyInfo.value?.response));
const canResendSurvey = computed(() => surveyEligible.value && !surveySubmitted.value && !surveySending.value);

function surveyAnswerField(key) {
    return (surveyFields.value || []).find(f => f.field_key === key);
}

function surveyRatingMax(key) {
    const m = parseInt(surveyAnswerField(key)?.config?.max, 10);
    return Number.isFinite(m) ? Math.min(10, Math.max(3, m)) : 5;
}

function surveyAnswerText(a) {
    if (Array.isArray(a.value)) {
        return a.type === 'cascade' ? a.value.join(' › ') : a.value.join(', ');
    }
    return String(a.value ?? '');
}

const SURVEY_EMAIL_KIND_LABELS = { initial: 'Initial invite', reminder: 'Reminder', manual: 'Manual resend' };
function surveyEmailKindLabel(kind) {
    return SURVEY_EMAIL_KIND_LABELS[kind] || kind;
}

async function loadSurvey() {
    if (!ticket.value || !meta.current_user?.id) return;
    if (isManager.value) {
        try {
            const [info, fields] = await Promise.all([
                api(`/tickets/${ticket.value.id}/survey`),
                api('/survey-fields'),
            ]);
            surveyInfo.value = info;
            surveyFields.value = fields || [];
        } catch {
            // Non-critical — the card just stays hidden.
        } finally {
            surveyLoaded.value = true;
        }
    } else if (isRequestor.value && isCompletedTicket.value) {
        try {
            requesterSurvey.value = await api(`/survey/${ticket.value.id}`);
        } catch {
            // No access (e.g. survey disabled) — hide the card.
        } finally {
            surveyLoaded.value = true;
        }
    } else {
        surveyLoaded.value = true;
    }
}

async function resendSurvey() {
    if (!canResendSurvey.value) return;
    surveySending.value = true;
    try {
        const res = await api(`/tickets/${ticket.value.id}/survey/resend`, { method: 'POST' });
        if (res?.emails) surveyInfo.value = { ...surveyInfo.value, emails: res.emails };
        toast.success('Survey email sent');
    } catch (e) {
        toast.error(e.message || 'Failed to send survey email');
    } finally {
        surveySending.value = false;
    }
}

// Assignee editing: managers + current step approver
const canEditAssignees = computed(() => {
    if (!ticket.value) return false;
    return isManager.value || ticket.value.can_approve_current_step === true;
});
const detailsEditing = ref(false);
const profileAssignee = ref(null);
const assigneePickerOpen = ref(false);
const assigneeSearch = ref('');
const assigneeSearchInput = ref(null);
const assigneeDropdownRef = ref(null);
const assignableUsers = computed(() => meta.assignable_users || []);
const filteredAssigneeUsers = computed(() => {
    const query = assigneeSearch.value.trim().toLowerCase();
    if (!query) return assignableUsers.value;
    return assignableUsers.value.filter(u => [u.display_name, u.user_login, u.user_email]
        .some(value => String(value || '').toLowerCase().includes(query)));
});

async function toggleAssigneePicker() {
    assigneePickerOpen.value = !assigneePickerOpen.value;
    if (assigneePickerOpen.value) {
        assigneeSearch.value = '';
        await nextTick();
        if (assigneeSearchInput.value) {
            assigneeSearchInput.value.focus();
        }
    }
}

function toggleDetailsEditing() {
    // Only reachable when not editing (the button hides in edit mode);
    // Save/Cancel are the exit paths. The assignee popover stays closed
    // until the user clicks the dropdown itself.
    detailsEditing.value = true;
}

// ── Linked tickets ────────────────────────────────────────
// Section lists ticket.linked_tickets (symmetric pairs). The picker reuses
// the /tickets list endpoint (search + status[] + pagination); Save replaces
// the whole set via PUT /tickets/:id/links. Same gate as assignee editing.
const linkedTickets = computed(() => ticket.value?.linked_tickets || []);
const linkPickerOpen = ref(false);
const linkResults = ref([]);
const linkLoading = ref(false);
const linkSearch = ref('');
const linkStatus = ref('');
const linkSelection = ref(new Set());
const linksSaving = ref(false);
let linkSearchTimer = null;

function openLinkPicker() {
    linkSelection.value = new Set(linkedTickets.value.map(t => t.id));
    linkSearch.value = '';
    linkStatus.value = '';
    linkPickerOpen.value = true;
    loadLinkOptions();
}

async function loadLinkOptions() {
    linkLoading.value = true;
    try {
        const params = new URLSearchParams();
        params.set('per_page', '25');
        params.set('sort', 'updated_at');
        params.set('order', 'desc');
        if (linkSearch.value.trim()) params.set('search', linkSearch.value.trim());
        if (linkStatus.value) params.append('status[]', linkStatus.value);
        const data = await api('/tickets', { params });
        linkResults.value = (data.items || []).filter(t => t.id !== ticket.value.id);
    } catch (e) {
        toast.error(e.message || 'Failed to load tickets');
    } finally {
        linkLoading.value = false;
    }
}

function queueLinkSearch() {
    clearTimeout(linkSearchTimer);
    linkSearchTimer = setTimeout(loadLinkOptions, 250);
}

function toggleLinkSelection(id) {
    const next = new Set(linkSelection.value);
    if (next.has(id)) next.delete(id); else next.add(id);
    linkSelection.value = next;
}

async function saveLinks() {
    if (!ticket.value || linksSaving.value) return;
    linksSaving.value = true;
    try {
        ticket.value = await api(`/tickets/${ticket.value.id}/links`, {
            method: 'PUT',
            body: { ticket_ids: [...linkSelection.value] },
        });
        linkPickerOpen.value = false;
        toast.success('Linked tickets updated');
    } catch (e) {
        toast.error(e.message || 'Failed to update links');
    } finally {
        linksSaving.value = false;
    }
}

// Links are added/removed only through the Manage links modal — the
// section rows are read-only links.

// Details card: due date and assignees are staged locally and only saved
// (and emailed) when the user clicks "Save changes". Each changed piece
// hits its own endpoint so the server fires exactly one notification per
// change type (rcmi_ticket_due_date_changed / rcmi_ticket_assignees_changed).
const draftDueDate = ref('');
const draftAssigneeIds = ref([]);
const detailsSaving = ref(false);

function syncDetailsDraft() {
    draftDueDate.value = ticket.value?.due_date || '';
    draftAssigneeIds.value = (ticket.value?.assignee_ids || []).map(id => Number(id));
    detailsEditing.value = false;
    assigneePickerOpen.value = false;
}
watch(ticket, syncDetailsDraft);

const sameIdSet = (a, b) => {
    const bb = (b || []).map(id => Number(id));
    return a.length === bb.length && a.every(id => bb.includes(Number(id)));
};

const detailsDirty = computed(() => {
    if (!ticket.value) return false;
    if (isManager.value && draftDueDate.value !== (ticket.value.due_date || '')) return true;
    if (canEditAssignees.value && !sameIdSet(draftAssigneeIds.value, ticket.value.assignee_ids)) return true;
    return false;
});

const draftAssignees = computed(() => {
    const known = [...(ticket.value?.assignees || []), ...assignableUsers.value];
    return draftAssigneeIds.value
        .map(id => known.find(u => Number(u.id) === id))
        .filter(Boolean);
});

const shownAssignees = computed(() =>
    canEditAssignees.value ? draftAssignees.value : (ticket.value?.assignees || []));

function isDraftAssignee(userId) {
    return draftAssigneeIds.value.includes(Number(userId));
}

function toggleAssigneeDraft(userId) {
    if (detailsSaving.value) return;
    const id = Number(userId);
    draftAssigneeIds.value = isDraftAssignee(id)
        ? draftAssigneeIds.value.filter(x => x !== id)
        : [...draftAssigneeIds.value, id];
}

async function saveDetails() {
    if (!ticket.value || !detailsDirty.value || detailsSaving.value) return;
    detailsSaving.value = true;
    const nextDue = draftDueDate.value;
    const nextIds = [...draftAssigneeIds.value];
    const dueChanged = isManager.value && nextDue !== (ticket.value.due_date || '');
    const assigneesChanged = canEditAssignees.value && !sameIdSet(nextIds, ticket.value.assignee_ids);
    try {
        if (dueChanged) {
            ticket.value = await api(`/tickets/${ticket.value.id}`, {
                method: 'PUT',
                body: { due_date: nextDue },
            });
        }
        if (assigneesChanged) {
            ticket.value = await api(`/tickets/${ticket.value.id}/assignees`, {
                method: 'POST',
                body: { assignee_ids: nextIds },
            });
        }
        toast.success('Details saved');
    } catch (e) {
        toast.error(e.message || 'Failed to save details');
    } finally {
        detailsSaving.value = false;
    }
}

function handleOutsideClick(e) {
    if (assigneeDropdownRef.value && !assigneeDropdownRef.value.contains(e.target)) {
        assigneePickerOpen.value = false;
    }
    if (rejectDropdownRef.value && !rejectDropdownRef.value.contains(e.target)) {
        rejectDropdownOpen.value = false;
    }
}

function canChangeStatus(newStatus) {
    if (!ticket.value) return false;
    const current = ticket.value.status;
    if (isManager.value) return newStatus !== current;
    if (!isAssignee.value) return false;
    return (current === 'Approved' && newStatus === 'In Progress')
        || (current === 'In Progress' && newStatus === 'Completed');
}

async function changeStatus(newStatus) {
    statusChanging.value = true;
    try {
        const body = { status: newStatus };
        if (newStatus === 'Rejected' && rejectMessage.value.trim()) {
            body.message = rejectMessage.value.trim();
        }
        await api(`/tickets/${ticket.value.id}/status`, { method: 'POST', body });
        ticket.value.status = newStatus;
        rejectOpen.value = false;
        rejectMessage.value = '';
        toast.success(`Status changed to ${newStatus}`);
        if (newStatus === 'Completed') {
            confetti.fireworks();
            loadSurvey();
        }
    } catch (e) {
        console.error('Failed to change status:', e);
        toast.error('Failed to change status. Please try again.');
    } finally {
        statusChanging.value = false;
    }
}

async function chainApprove() {
    approvalBusy.value = true;
    try {
        const body = {};
        if (chainRejectComment.value.trim()) body.comment = chainRejectComment.value.trim();
        const res = await api(`/tickets/${ticket.value.id}/approve`, { method: 'POST', body });
        if (res.ticket_status === 'Approved') {
            toast.success('Ticket approved!');
            confetti.burst();
        } else {
            toast.success('Step approved — advanced to next step');
        }
        await loadTicket();
        chainRejectOpen.value = false;
        chainRejectComment.value = '';
    } catch (e) {
        toast.error(e.message || 'Failed to approve step');
    } finally {
        approvalBusy.value = false;
    }
}

function selectRejectType(type) {
    chainRejectType.value = type;
    rejectDropdownOpen.value = false;
    chainRejectOpen.value = true;
}

function cancelReject() {
    chainRejectOpen.value = false;
    chainRejectComment.value = '';
    chainRejectType.value = 'restart';
}

async function chainReject() {
    approvalBusy.value = true;
    try {
        const body = {
            comment: chainRejectComment.value.trim(),
            on_reject: chainRejectType.value,
        };
        const res = await api(`/tickets/${ticket.value.id}/reject`, { method: 'POST', body });
        const msg = res.on_reject === 'terminal' ? 'Ticket rejected (closed)'
            : res.on_reject === 'back_one' ? 'Sent back to previous approver'
            : 'Sent back to requestor for revision';
        toast.success(msg);
        await loadTicket();
        cancelReject();
    } catch (e) {
        toast.error(e.message || 'Failed to reject step');
    } finally {
        approvalBusy.value = false;
    }
}

async function deleteTicket() {
    deleting.value = true;
    try {
        await api(`/tickets/${ticket.value.id}`, { method: 'DELETE' });
        toast.success('Ticket deleted');
        router.push('/');
    } catch (e) {
        console.error('Failed to delete ticket:', e);
        toast.error('Failed to delete ticket. Please try again.');
        confirmDelete.value = false;
    } finally {
        deleting.value = false;
    }
}

async function sendToHeaven() {
    archiving.value = true;
    try {
        await api(`/tickets/${ticket.value.id}/archive`, { method: 'POST' });
        toast.success('Ticket sent to Heaven ☁️');
        router.push('/');
    } catch (e) {
        toast.error(e.message || 'Failed to send ticket to Heaven');
    } finally {
        archiving.value = false;
    }
}

async function resurrectTicket() {
    archiving.value = true;
    try {
        await api(`/tickets/${ticket.value.id}/resurrect`, { method: 'POST' });
        toast.success('Ticket resurrected!');
        await loadTicket();
    } catch (e) {
        toast.error(e.message || 'Failed to resurrect ticket');
    } finally {
        archiving.value = false;
    }
}

async function copyLink() {
    try {
        await navigator.clipboard.writeText(window.location.href);
        toast.success('Link copied to clipboard');
    } catch (e) {
        console.error('Failed to copy link:', e);
        toast.error('Failed to copy link');
    }
}

function formatDate(d) {
    if (!d) return '';
    return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function formatDateTime(d) {
    if (!d) return '';
    return new Date(d).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
}

function formatSize(bytes) {
    if (!bytes) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

function fileIcon(mime) {
    if (!mime) return 'file-generic';
    if (mime.startsWith('image/')) return 'file-image';
    if (mime === 'application/pdf') return 'file-text';
    if (mime.includes('zip')) return 'file-archive';
    if (mime.includes('word') || mime.includes('document')) return 'file-word';
    if (mime.includes('sheet') || mime.includes('excel')) return 'file-spreadsheet';
    return 'file-generic';
}

function initials(name) {
    if (!name) return '?';
    return name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
}

function downloadUrl(id) {
    const config = window.rcmiTickets || {};
    const base = `${config.apiBase}/attachments/${id}/download`;
    // Append nonce as query param so the <a href> click authenticates
    const sep = base.includes('?') ? '&' : '?';
    return `${base}${sep}_wpnonce=${config.nonce}`;
}

async function loadMeta() {
    try {
        const data = await api('/meta');
        Object.assign(meta, data);
    } catch (e) {
        console.error('Failed to load meta:', e);
    }
}

async function loadTicket() {
    try {
        ticket.value = await api(`/tickets/${props.id}`);
        await loadSurvey();
    } catch (e) {
        loadError.value = e.status === 404 ? 'Ticket not found.' : 'Failed to load ticket.';
    } finally {
        loading.value = false;
    }
}

// In-place navigation between tickets (e.g. clicking a linked ticket)
// reuses this component — reload when the route id changes.
watch(() => props.id, (id, old) => {
    if (!id || id === old) return;
    loading.value = true;
    loadError.value = '';
    linkPickerOpen.value = false;
    loadTicket();
});

onMounted(async () => {
    document.addEventListener('click', handleOutsideClick);
    await loadMeta();
    await loadTicket();
});

onBeforeUnmount(() => {
    document.removeEventListener('click', handleOutsideClick);
});
</script>
