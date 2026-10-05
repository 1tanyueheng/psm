import { Component } from 'react'
import { Button } from './ui'

/**
 * Catches render errors in a page so one broken component cannot blank the app.
 *
 * Without this, any uncaught error during render unmounts the whole React tree
 * and the user is left staring at an empty page with no clue why — which is
 * exactly what happened when the archive page called `.map()` on a string. The
 * server was healthy, the network tab was clean, and the screen was simply
 * white.
 *
 * Placed around the routed content (not the whole app) so the shell survives:
 * the sidebar still works, so the user can navigate somewhere else instead of
 * being stuck. The caller keys it on the pathname, so changing page clears the
 * error automatically.
 *
 * The message and stack are shown rather than hidden. This is a student
 * project with `APP_DEBUG` on locally, and a developer seeing the real error
 * beats a polite apology that says nothing.
 */
export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props)
    this.state = { error: null }
  }

  static getDerivedStateFromError(error) {
    return { error }
  }

  componentDidCatch(error, info) {
    // Keep it in the console as well as on screen: the console copy survives a
    // navigation away, the on-screen one does not.
    console.error('Unhandled render error:', error, info?.componentStack)
  }

  render() {
    const { error } = this.state
    const { children } = this.props

    if (!error) return children

    return (
      <div className="mx-auto max-w-2xl rounded-lg border border-rose-200 bg-rose-50/60 p-6">
        <h2 className="text-base font-semibold text-rose-900">
          This page could not be displayed
        </h2>
        <p className="mt-1 text-sm text-rose-800">
          Something went wrong while rendering it. The rest of the app still
          works — use the navigation to go somewhere else, or try again.
        </p>

        <pre className="mt-4 max-h-48 overflow-auto rounded-md bg-white/70 p-3 text-xs text-rose-900">
          {String(error?.message ?? error)}
          {error?.stack ? `\n\n${error.stack.split('\n').slice(0, 6).join('\n')}` : ''}
        </pre>

        <div className="mt-4 flex gap-2">
          <Button size="sm" onClick={() => this.setState({ error: null })}>
            Try again
          </Button>
          <Button size="sm" variant="secondary" onClick={() => window.location.reload()}>
            Reload the page
          </Button>
        </div>
      </div>
    )
  }
}
