import android.app.Activity;
import android.os.Bundle;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.util.Log;
import android.widget.Toast;
import java.net.HttpURLConnection;
import java.net.URL;
import java.io.IOException;

public class PaymentWebviewActivity extends Activity {

    private static final String TAG = "PaymentWebviewActivity";
    private WebView webView;
    private static final int TIMEOUT = 15000; // 15 seconds

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_payment_webview);

        webView = findViewById(R.id.webview);
        configureWebView();
        loadUrl("https://example.com/payment");
    }

    private void configureWebView() {
        WebSettings webSettings = webView.getSettings();
        webSettings.setJavaScriptEnabled(true);
        webSettings.setDomStorageEnabled(true);
        webSettings.setSupportZoom(false);
        webView.setWebViewClient(new WebViewClient());
    }

    private void loadUrl(final String url) {
        new Thread(() -> {
            try {
                HttpURLConnection urlConnection = (HttpURLConnection) new URL(url).openConnection();
                urlConnection.setConnectTimeout(TIMEOUT);
                urlConnection.setReadTimeout(TIMEOUT);
                urlConnection.connect();
                int responseCode = urlConnection.getResponseCode();
                if (responseCode == HttpURLConnection.HTTP_OK) {
                    runOnUiThread(() -> webView.loadUrl(url));
                } else {
                    runOnUiThread(() -> Toast.makeText(PaymentWebviewActivity.this, "Failed to load URL: " + responseCode, Toast.LENGTH_SHORT).show());
                }
            } catch (IOException e) {
                Log.e(TAG, "Error loading URL: " + e.getMessage());
                runOnUiThread(() -> Toast.makeText(PaymentWebviewActivity.this, "Network error: " + e.getMessage(), Toast.LENGTH_SHORT).show());
            }
        }).start();
    }
}