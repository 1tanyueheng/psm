import { Suspense, lazy } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'

import AppLayout from './components/AppLayout'
import { RequireAuth, RequireCapability, RequireRole, RedirectIfAuthenticated } from './components/guards'
import { Spinner } from './components/ui'

// ---------------------------------------------------------------------
// Lazy loading
// ---------------------------------------------------------------------
// Sign-in and the shell are eager so the app paints immediately. Everything
// else is split, which keeps the first download small — a student opening
// their dashboard should not pay for the reporting screens.

import LoginPage from './pages/auth/LoginPage'
import DashboardRouter from './pages/DashboardRouter'

const ForgotPasswordPage = lazy(() => import('./pages/auth/ForgotPasswordPage'))
const ResetPasswordPage = lazy(() => import('./pages/auth/ResetPasswordPage'))
const ChangePasswordPage = lazy(() => import('./pages/auth/ChangePasswordPage'))

const StudentDashboard = lazy(() => import('./pages/student/StudentDashboard'))
const SupervisorDashboard = lazy(() => import('./pages/supervisor/SupervisorDashboard'))
const CoordinatorDashboard = lazy(() => import('./pages/coordinator/CoordinatorDashboard'))
const MarkSubmissionPage = lazy(() => import('./pages/coordinator/MarkSubmissionPage'))
const PanelWorkPage = lazy(() => import('./pages/panel/PanelWorkPage'))
const AdminDashboard = lazy(() => import('./pages/admin/AdminDashboard'))
const UserListPage = lazy(() => import('./pages/admin/UserListPage'))
const SemesterListPage = lazy(() => import('./pages/admin/SemesterListPage'))

const ProjectListPage = lazy(() => import('./pages/projects/ProjectListPage'))
const ProjectDetailPage = lazy(() => import('./pages/projects/ProjectDetailPage'))
const ProjectRegisterPage = lazy(() => import('./pages/projects/ProjectRegisterPage'))
const RegistrationListPage = lazy(() => import('./pages/registrations/RegistrationListPage'))
const LampiranAFormPage = lazy(() => import('./pages/registrations/LampiranAFormPage'))
const RegistrationDetailPage = lazy(() => import('./pages/registrations/RegistrationDetailPage'))
const MilestoneListPage = lazy(() => import('./pages/milestones/MilestoneListPage'))
const MilestoneDetailPage = lazy(() => import('./pages/milestones/MilestoneDetailPage'))

const EvaluationListPage = lazy(() => import('./pages/evaluations/EvaluationListPage'))
const EvaluationFormPage = lazy(() => import('./pages/evaluations/EvaluationFormPage'))
const MarkListPage = lazy(() => import('./pages/evaluations/MarkListPage'))
const AssessmentWindowPage = lazy(() => import('./pages/coordinator/AssessmentWindowPage'))
const RubricListPage = lazy(() => import('./pages/evaluations/RubricListPage'))

const AssignmentPage = lazy(() => import('./pages/assignments/AssignmentPage'))
const PanelAssignmentPage = lazy(() => import('./pages/assignments/PanelAssignmentPage'))
const ReportPage = lazy(() => import('./pages/reports/ReportPage'))
const ArchivePage = lazy(() => import('./pages/archive/ArchivePage'))
const ArchiveDetailPage = lazy(() => import('./pages/archive/ArchiveDetailPage'))
const AuditLogPage = lazy(() => import('./pages/archive/AuditLogPage'))

const NotificationPage = lazy(() => import('./pages/account/NotificationPage'))
const ProfilePage = lazy(() => import('./pages/account/ProfilePage'))

const NotFoundPage = lazy(() => import('./pages/NotFoundPage'))

function PageFallback() {
  return (
    <div className="py-20">
      <Spinner label="Loading…" />
    </div>
  )
}

export default function AppRoutes() {
  return (
    <Suspense fallback={<PageFallback />}>
      <Routes>
        {/* ---------------- Auth ---------------- */}
        <Route
          path="/login"
          element={
            <RedirectIfAuthenticated>
              <LoginPage />
            </RedirectIfAuthenticated>
          }
        />
        <Route path="/forgot-password" element={<ForgotPasswordPage />} />
        <Route path="/reset-password" element={<ResetPasswordPage />} />

        {/* Accessible while signed in — a user with a forced password change
            must be able to reach it, so it sits inside RequireAuth */}
        <Route
          path="/change-password"
          element={
            <RequireAuth>
              <ChangePasswordPage />
            </RequireAuth>
          }
        />

        {/* ---------------- Authenticated application ---------------- */}
        <Route
          element={
            <RequireAuth>
              <AppLayout />
            </RequireAuth>
          }
        >
          {/* Role-agnostic dispatch: sends each role to its own dashboard */}
          <Route path="/dashboard" element={<DashboardRouter />} />

          {/* --- Dashboards --- */}
          <Route
            path="/student/dashboard"
            element={
              <RequireRole roles={['student']}>
                <StudentDashboard />
              </RequireRole>
            }
          />
          <Route
            path="/supervisor/dashboard"
            element={
              <RequireRole roles={['supervisor']}>
                <SupervisorDashboard />
              </RequireRole>
            }
          />
          <Route
            path="/coordinator/dashboard"
            element={
              <RequireCapability capability="viewCohortAnalytics">
                <CoordinatorDashboard />
              </RequireCapability>
            }
          />
          <Route
            path="/coordinator/projects/:project/students/:student/mark-submission"
            element={
              <RequireCapability capability="manageMarkSubmissions">
                <MarkSubmissionPage />
              </RequireCapability>
            }
          />
          {/* The panel view. Same role as /supervisor/dashboard — being on a
              panel is a seating, not a separate kind of account. */}
          <Route
            path="/panel/dashboard"
            element={
              <RequireRole roles={['supervisor']}>
                <PanelWorkPage />
              </RequireRole>
            }
          />
          <Route
            path="/admin/dashboard"
            element={
              <RequireRole roles={['admin']}>
                <AdminDashboard />
              </RequireRole>
            }
          />

          {/* --- Module 3: projects --- */}
          <Route path="/projects" element={<ProjectListPage />} />
          <Route
            path="/projects/new"
            element={
              <RequireRole roles={['student']}>
                <ProjectRegisterPage />
              </RequireRole>
            }
          />
          <Route path="/projects/:id" element={<ProjectDetailPage />} />
          <Route path="/projects/:id/edit" element={<ProjectDetailPage />} />

          {/* --- Module 2/3: registration flow (Lampiran A & B) --- */}
          <Route path="/registrations" element={<RegistrationListPage />} />
          <Route
            path="/registrations/new"
            element={
              <RequireRole roles={['student']}>
                <LampiranAFormPage />
              </RequireRole>
            }
          />
          <Route path="/registrations/:id" element={<RegistrationDetailPage />} />

          {/* --- Module 3: milestones --- */}
          <Route path="/milestones" element={<MilestoneListPage />} />
          <Route path="/milestones/:id" element={<MilestoneDetailPage />} />

          {/* --- Module 2: assignments --- */}
          <Route
            path="/assignments"
            element={
              <RequireCapability capability="assignSupervisors">
                <AssignmentPage />
              </RequireCapability>
            }
          />
          {/* Seating a panel is the same audience as allocating supervisors —
              both are the coordinator's pairing decisions. */}
          <Route
            path="/assignments/panels"
            element={
              <RequireCapability capability="assignSupervisors">
                <PanelAssignmentPage />
              </RequireCapability>
            }
          />

          {/* --- Module 4: assessment --- */}
          <Route path="/evaluations" element={<EvaluationListPage />} />
          {/* The coordinator's open/close control over marking. Assessors read
              the same windows from their marking page. */}
          <Route
            path="/assessment"
            element={
              <RequireCapability capability="releaseMarks">
                <AssessmentWindowPage />
              </RequireCapability>
            }
          />
          <Route path="/evaluations/:id" element={<EvaluationFormPage />} />
          <Route
            path="/marks"
            element={
              <RequireCapability capability="releaseMarks">
                <MarkListPage />
              </RequireCapability>
            }
          />
          <Route path="/rubrics" element={<RubricListPage />} />

          {/* --- Module 5: reporting --- */}
          <Route
            path="/reports"
            element={
              <RequireCapability capability="viewCohortAnalytics">
                <ReportPage />
              </RequireCapability>
            }
          />

          {/* --- Module 3: the term that owns both batches --- */}
          <Route
            path="/semesters"
            element={
              <RequireCapability capability="manageSemesters">
                <SemesterListPage />
              </RequireCapability>
            }
          />

          {/* --- Module 2: user administration --- */}
          <Route
            path="/users"
            element={
              <RequireCapability capability="manageUsers">
                <UserListPage />
              </RequireCapability>
            }
          />

          {/* --- Module 7: archive and audit --- */}
          <Route path="/archive" element={<ArchivePage />} />
          <Route path="/archive/:id" element={<ArchiveDetailPage />} />
          <Route
            path="/audit"
            element={
              <RequireCapability capability="viewAuditLog">
                <AuditLogPage />
              </RequireCapability>
            }
          />

          {/* --- Module 6 and account --- */}
          <Route path="/notifications" element={<NotificationPage />} />
          <Route path="/profile" element={<ProfilePage />} />
        </Route>

        {/* ---------------- Fallbacks ---------------- */}
        <Route path="/" element={<Navigate to="/dashboard" replace />} />
        <Route path="*" element={<NotFoundPage />} />
      </Routes>
    </Suspense>
  )
}
