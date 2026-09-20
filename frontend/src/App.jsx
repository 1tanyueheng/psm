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
const ExaminerDashboard = lazy(() => import('./pages/examiner/ExaminerDashboard'))
const AdminDashboard = lazy(() => import('./pages/admin/AdminDashboard'))
const UserListPage = lazy(() => import('./pages/admin/UserListPage'))

const ProjectListPage = lazy(() => import('./pages/projects/ProjectListPage'))
const ProjectDetailPage = lazy(() => import('./pages/projects/ProjectDetailPage'))
const ProjectRegisterPage = lazy(() => import('./pages/projects/ProjectRegisterPage'))
const MilestoneListPage = lazy(() => import('./pages/milestones/MilestoneListPage'))
const MilestoneDetailPage = lazy(() => import('./pages/milestones/MilestoneDetailPage'))

const EvaluationListPage = lazy(() => import('./pages/evaluations/EvaluationListPage'))
const EvaluationFormPage = lazy(() => import('./pages/evaluations/EvaluationFormPage'))
const GradeListPage = lazy(() => import('./pages/evaluations/GradeListPage'))
const RubricListPage = lazy(() => import('./pages/evaluations/RubricListPage'))

const AssignmentPage = lazy(() => import('./pages/assignments/AssignmentPage'))
const ReportPage = lazy(() => import('./pages/reports/ReportPage'))
const ArchivePage = lazy(() => import('./pages/archive/ArchivePage'))
const ArchiveDetailPage = lazy(() => import('./pages/archive/ArchiveDetailPage'))
const AuditLogPage = lazy(() => import('./pages/archive/AuditLogPage'))

const LeaderboardAdminPage = lazy(() => import('./pages/leaderboards/LeaderboardAdminPage'))
const LeaderboardBuilderPage = lazy(() => import('./pages/leaderboards/LeaderboardBuilderPage'))

const NotificationPage = lazy(() => import('./pages/account/NotificationPage'))
const ProfilePage = lazy(() => import('./pages/account/ProfilePage'))

// Module 8 — public, no authentication, no app shell
const PublicLeaderboardPage = lazy(() => import('./pages/public/PublicLeaderboardPage'))

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
        {/* ---------------- Public ---------------- */}

        {/* Module 8 — genuinely public. Outside RequireAuth and outside the
            app shell, because a visitor has no account and should not be
            shown a signed-in layout. */}
        <Route path="/leaderboard" element={<PublicLeaderboardPage />} />
        <Route path="/leaderboard/:slug" element={<PublicLeaderboardPage />} />

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
            path="/examiner/dashboard"
            element={
              <RequireRole roles={['examiner']}>
                <ExaminerDashboard />
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

          {/* --- Module 4: assessment --- */}
          <Route path="/evaluations" element={<EvaluationListPage />} />
          <Route path="/evaluations/:id" element={<EvaluationFormPage />} />
          <Route
            path="/grades"
            element={
              <RequireCapability capability="releaseGrades">
                <GradeListPage />
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

          {/* --- Module 8: staff-side leaderboard management --- */}
          <Route
            path="/leaderboards"
            element={
              <RequireCapability capability="manageLeaderboard">
                <LeaderboardAdminPage />
              </RequireCapability>
            }
          />
          <Route
            path="/leaderboards/:id"
            element={
              <RequireCapability capability="manageLeaderboard">
                <LeaderboardBuilderPage />
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
