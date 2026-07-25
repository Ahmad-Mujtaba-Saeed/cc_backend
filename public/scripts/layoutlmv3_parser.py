from transformers import LayoutLMv3Processor, LayoutLMv3ForTokenClassification
from pdf2image import convert_from_path
from PIL import Image
import torch
import json
import sys, os

# Load fine-tuned model
model = LayoutLMv3ForTokenClassification.from_pretrained("nielsr/layoutlmv3-funsd")
processor = LayoutLMv3Processor.from_pretrained("nielsr/layoutlmv3-funsd")

def parse_with_finetuned_model(pdf_path):
    try:
        pages = convert_from_path(pdf_path, dpi=300)
        if not pages:
            return { "error": "No pages found in PDF." }

        page = pages[0]
        encoding = processor(images=page, return_tensors="pt")
        outputs = model(**encoding)
        logits = outputs.logits
        predicted_ids = torch.argmax(logits, dim=2)

        tokens = processor.tokenizer.convert_ids_to_tokens(encoding['input_ids'][0])
        labels = predicted_ids[0].tolist()

        extracted = []
        for token, label_id in zip(tokens, labels):
            label = model.config.id2label[label_id]
            if label != 'O':
                extracted.append({ "token": token, "label": label })

        return {
            "page": 1,
            "tokens": extracted
        }

    except Exception as e:
        return { "error": str(e) }


# Main execution
if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(json.dumps({ "error": "Usage: python parse_resume.py <file_path>" }))
        sys.exit(1)

    file_path = sys.argv[1]

    if not os.path.exists(file_path):
        print(json.dumps({ "error": "File not found" }))
        sys.exit(1)

    result = parse_with_finetuned_model(file_path)
    sys.stdout.buffer.write(json.dumps(result, ensure_ascii=False).encode("utf-8"))
