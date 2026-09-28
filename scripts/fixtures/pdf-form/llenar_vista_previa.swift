import PDFKit
import Foundation
let args = CommandLine.arguments
let doc = PDFDocument(url: URL(fileURLWithPath: args[1]))!
let data = try! Data(contentsOf: URL(fileURLWithPath: args[3]))
let vals = try! JSONSerialization.jsonObject(with: data) as! [String: String]
for i in 0..<doc.pageCount {
  for a in doc.page(at: i)!.annotations {
    if let name = a.fieldName, let v = vals[name] { a.widgetStringValue = v }
  }
}
doc.write(to: URL(fileURLWithPath: args[2]))
print("ok")
