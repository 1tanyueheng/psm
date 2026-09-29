import { useCallback, useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { userApi } from '../../api/endpoints'
import { unwrapPaged } from '../../api/client'
import { useAuth } from '../../context/AuthContext'
import {
  Card, CardHeader, PageHeader, Badge, Avatar, EmptyState, Spinner,
  ErrorState, Button, Select, DataTable, Td, Input,
} from '../../components/ui'
import { formatDate, formatDateTime } from '../../lib/format'
import { roleLabel, roleTone } from '../../lib/permissions'

/**
 * Module 2 — user administration.
 *
 * The job of this screen is account lifecycle, not profile editing: an admin
 * arrives here to find an account, unblock it, or issue a password reset. The
 * actions are therefore ordered by how often they are needed and how safely
 * they can be undone.
 *
 * Two deliberate omissions:
 *
 *  - There is no delete button. `DELETE /users/{id}` soft-deletes, which in a
 *    system with an audit trail and archived projects is rarely the right
 *    answer. Deactivating is reversible; deleting hides a student's history.
 *    The API supports both, but offering only deactivate here is the safer
 *    default.
 *  - There is no password field. Reset emails are the only supported path —
 *    an admin choosing a user's password breaks the audit story, because the
 *    reset cannot be attributed to the account holder.
 */
export default function UserListPage() {
  const { user: me } = useAuth()
  const [params, setParams] = useSearchParams()

  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(null)
  const [actionError, setActionError] = useState(null)
  const [notice, setNotice] = useState(null)

  const role = params.get('role') ?? ''
  const status = params.get('status') ?? ''
  const search = params.get('q') ?? ''

  const setFilter = (key, value) => {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    next.delete('page')
    setParams(next, { replace: true })
  }

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const res = await userApi.list({
        role: role || undefined,
        status: status || undefined,
        q: search || undefined,
        per_page: 100,
      })
      const { items, meta: pageMeta } = unwrapPaged(res)
      setRows(items)
      setMeta(pageMeta)
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [role, status, search])

  useEffect(() => {
    load()
  }, [load])

  /**
   * Run one account action, then refresh.
   *
   * Every action here changes server state that other people depend on
   * (a suspended supervisor stops being assignable), so the list is reloaded
   * rather than patched locally — the response is the source of truth.
   */
  async function act(key, fn, successMessage) {
    setBusy(key)
    setActionError(null)
    setNotice(null)
    try {
      await fn()
      setNotice(successMessage)
      await load()
    } catch (err) {
      setActionError(err?.message ?? 'فشل هذا الإجراء.')
    } finally {
      setBusy(null)
    }
  }

  const counts = useMemo(() => {
    const out = { total: meta?.total ?? rows.length, suspended: 0, pending: 0 }
    for (const row of rows) {
      if (row.is_active === false || row.status === 'suspended') out.suspended += 1
      if (row.status === 'pending' || row.must_change_password) out.pending += 1
    }
    return out
  }, [rows, meta])

  if (loading && rows.length === 0) return <Spinner label="جارٍ تحميل الحسابات" />
  if (error) return <ErrorState error={error} />

  return (
    <div className="space-y-6">
      <PageHeader
        title="حسابات المستخدمين"
        subtitle={
          meta?.total != null ? `${meta.total} ${meta.total === 1 ? 'حساب' : 'حسابات'}` : undefined
        }
      />

      {notice && (
        <Card className="border-emerald-200 bg-emerald-50/60">
          <p className="text-sm text-emerald-900">{notice}</p>
        </Card>
      )}
      {actionError && <ErrorState error={{ message: actionError }} />}

      <div className="grid gap-4 sm:grid-cols-3">
        <Card>
          <p className="text-xs uppercase tracking-wide text-slate-500">الحسابات</p>
          <p className="mt-1 text-2xl font-semibold tabular-nums text-slate-900">
            {counts.total}
          </p>
        </Card>
        <Card>
          <p className="text-xs uppercase tracking-wide text-slate-500">موقوفة</p>
          <p className="mt-1 text-2xl font-semibold tabular-nums text-slate-900">
            {counts.suspended}
          </p>
        </Card>
        <Card>
          <p className="text-xs uppercase tracking-wide text-slate-500">بانتظار تسجيل الدخول</p>
          <p className="mt-1 text-2xl font-semibold tabular-nums text-slate-900">
            {counts.pending}
          </p>
        </Card>
      </div>

      <Card>
        <CardHeader title="تصفية" />
        <div className="grid gap-3 sm:grid-cols-3">
          <Input
            type="search"
            placeholder="الاسم أو البريد أو رقم الموظف"
            defaultValue={search}
            onKeyDown={(e) => {
              if (e.key === 'Enter') setFilter('q', e.currentTarget.value.trim())
            }}
            aria-label="البحث في الحسابات"
          />
          <Select
            value={role}
            onChange={(e) => setFilter('role', e.target.value)}
            aria-label="تصفية حسب الدور"
          >
            <option value="">كل الأدوار</option>
            <option value="student">الطلاب</option>
            <option value="supervisor">المشرفون</option>
            <option value="coordinator">المنسقون</option>
            <option value="examiner">الممتحنون</option>
            <option value="admin">المسؤولون</option>
          </Select>
          <Select
            value={status}
            onChange={(e) => setFilter('status', e.target.value)}
            aria-label="تصفية حسب الحالة"
          >
            <option value="">كل الحالات</option>
            <option value="active">نشط</option>
            <option value="suspended">موقوف</option>
            <option value="pending">مدعو، لم يسجّل الدخول بعد</option>
          </Select>
        </div>
      </Card>

      {rows.length === 0 ? (
        <Card>
          <EmptyState
            title="لا توجد حسابات مطابقة"
            description="عدّل التصفية، أو تأكد من استيراد الحسابات لهذه الدورة."
          />
        </Card>
      ) : (
        <Card className="overflow-hidden p-0">
          <div className="overflow-x-auto">
            <DataTable
              columns={[
                { key: 'name', label: 'الاسم' },
                { key: 'role', label: 'الدور' },
                { key: 'identifier', label: 'المعرّف' },
                { key: 'status', label: 'الحالة' },
                { key: 'last_login', label: 'آخر تسجيل دخول' },
                { key: 'actions', label: 'إجراءات' },
              ]}
              rows={rows}
              render={(row) => {
                const suspended = row.is_active === false || row.status === 'suspended'
                const isSelf = row.id === me?.id

                return [
                  <Td key="name">
                    <div className="flex items-center gap-2">
                      <Avatar name={row.name} size="sm" />
                      <div className="min-w-0">
                        <div className="truncate text-sm font-medium text-slate-800">
                          {row.name}
                          {isSelf && (
                            <span className="ms-1.5 text-xs font-normal text-slate-400">
                              أنت
                            </span>
                          )}
                        </div>
                        <div className="truncate text-xs text-slate-500">{row.email}</div>
                      </div>
                    </div>
                  </Td>,
                  <Td key="role">
                    <Badge tone={roleTone(row.role)}>{roleLabel(row.role)}</Badge>
                  </Td>,
                  <Td key="identifier" className="font-mono text-xs text-slate-500">
                    {row.student_id ?? row.staff_no ?? '—'}
                  </Td>,
                  <Td key="status">
                    <Badge tone={suspended ? 'danger' : 'success'}>
                      {suspended ? 'موقوف' : (row.status ?? 'نشط')}
                    </Badge>
                    {row.must_change_password && (
                      <div className="mt-0.5 text-xs text-amber-600">
                        يجب تغيير كلمة المرور
                      </div>
                    )}
                  </Td>,
                  <Td key="last_login" className="text-sm text-slate-600">
                    {row.last_login_at ? (
                      <>
                        <div>{formatDate(row.last_login_at)}</div>
                        <div className="text-xs text-slate-400">
                          {formatDateTime(row.last_login_at)}
                        </div>
                      </>
                    ) : (
                      <span className="text-slate-400">أبدًا</span>
                    )}
                  </Td>,
                  <Td key="actions">
                    <div className="flex flex-wrap gap-1.5">
                      {/*
                          Deactivating yourself would lock you out of the very
                          screen you would use to undo it. The API permits it;
                          this UI declines to offer it.
                        */}
                      <Button
                        size="sm"
                        variant="secondary"
                        disabled={isSelf || busy === `toggle-${row.id}`}
                        onClick={() =>
                          act(
                            `toggle-${row.id}`,
                            () =>
                              suspended ? userApi.reactivate(row.id) : userApi.deactivate(row.id),
                            suspended
                              ? `يمكن لـ ${row.name} تسجيل الدخول مجددًا.`
                              : `لم يعد ${row.name} قادرًا على تسجيل الدخول.`,
                          )
                        }
                      >
                        {busy === `toggle-${row.id}`
                          ? 'جارٍ التنفيذ…'
                          : suspended
                            ? 'إعادة تفعيل'
                            : 'إيقاف'}
                      </Button>

                      <Button
                        size="sm"
                        variant="ghost"
                        disabled={busy === `reset-${row.id}`}
                        onClick={() =>
                          act(
                            `reset-${row.id}`,
                            () => userApi.sendPasswordReset(row.id),
                            `سيستلم ${row.name} بريدًا لإعادة تعيين كلمة المرور.`,
                          )
                        }
                      >
                        {busy === `reset-${row.id}` ? 'جارٍ الإرسال…' : 'إعادة تعيين كلمة المرور'}
                      </Button>

                      {row.locked_at && (
                        <Button
                          size="sm"
                          variant="ghost"
                          disabled={busy === `unlock-${row.id}`}
                          onClick={() =>
                            act(
                              `unlock-${row.id}`,
                              () => userApi.unlock(row.id),
                              `تم فتح قفل ${row.name}.`,
                            )
                          }
                        >
                          فك القفل
                        </Button>
                      )}
                    </div>
                  </Td>,
                ]
              }}
            />
          </div>
        </Card>
      )}

      <Card>
        <CardHeader title="ماذا تفعل هذه الإجراءات" />
        <ul className="space-y-2 text-sm text-slate-600">
          <li>
            <span className="font-medium text-slate-800">إيقاف</span> يمنع تسجيل الدخول لكنه يبقي
            الحساب ومشاريعه وسجل التدقيق سليمة. وهو قابل للعكس.
          </li>
          <li>
            <span className="font-medium text-slate-800">إعادة تعيين كلمة المرور</span> ترسل رابطًا
            لاستخدام واحد عبر البريد لصاحب الحساب. لا يرى أي مسؤول كلمة المرور أو يضعها، فيبقى
            التغيير قابلًا للإسناد.
          </li>
          <li>
            <span className="font-medium text-slate-800">فك القفل</span> يزيل قفلًا ناتجًا عن محاولات
            تسجيل دخول فاشلة متكررة. يظهر فقط للحسابات المقفلة فعليًا.
          </li>
        </ul>
        <p className="mt-3 text-xs text-slate-500">
          لا تُحذف الحسابات من هذه الشاشة أبدًا. سجل الطالب مطلوب للأرشيف وسجل التدقيق حتى بعد
          تخرجه بوقت طويل.
        </p>
      </Card>
    </div>
  )
}
